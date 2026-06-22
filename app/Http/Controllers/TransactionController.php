<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Property;
use App\Models\Transaction;
use App\Models\Installment;
use App\Services\InstallmentService;
use Illuminate\Support\Facades\Auth;
use Midtrans\Config;
use Midtrans\Snap;
use Barryvdh\DomPDF\Facade\Pdf;

class TransactionController extends Controller
{
    public function __construct()
    {
        Config::$serverKey = config('services.midtrans.server_key');
        Config::$isProduction = config('services.midtrans.is_production');
        Config::$isSanitized = config('services.midtrans.is_sanitized');
        Config::$is3ds = config('services.midtrans.is_3ds');
    }

    public function checkout(Request $request, $id)
    {
        $request->validate([
            'method' => 'sometimes|in:booking,dp,cash',
            'installment' => 'sometimes|in:none,monthly,quarterly,semi_annually',
        ]);

        $property = \App\Models\Property::findOrFail($id);
        $price_base = $property->price > 0 ? $property->price : config('payment.default_price');

        $method = $request->get('method', 'booking');
        $installmentPlan = $request->get('installment', 'none');

        if ($method === 'booking') {
            $price_raw = config('payment.booking_fee');
        } elseif ($method === 'dp') {
            $price_raw = $price_base * config('payment.dp_rate');
        } else {
            $price_raw = $price_base;
        }

        $ipl = $property->ipl_cost > 0 ? $property->ipl_cost : ($price_raw * config('payment.ipl_rate'));
        $tax = $property->tax_cost > 0 ? $property->tax_cost : ($price_raw * config('payment.tax_rate'));
        $admin = $property->admin_cost > 0 ? $property->admin_cost : config('payment.admin_fee');

        $grossAmountOriginal = $price_raw + $ipl + $tax + $admin;

        $installmentData = null;
        $installmentTotal = $grossAmountOriginal;
        $serviceFee = 0;
        $installmentCount = 1;
        $installmentPeriodMonths = 1;

        if ($installmentPlan !== 'none' && in_array($method, ['booking', 'dp'])) {
            $installmentData = InstallmentService::calculate($grossAmountOriginal, $installmentPlan);
            $installmentTotal = $installmentData['total_with_fee'];
            $serviceFee = $installmentData['service_fee'];
            $installmentCount = $installmentData['installment_count'];
            $installmentPeriodMonths = $installmentData['installment_period_months'];
        }

        $grossAmount = $installmentTotal;

        $property->price_raw = $price_raw;
        $property->ipl = $ipl;
        $property->tax = $tax;
        $property->admin = $admin;

        $project = [
            'id'        => $property->id,
            'name'      => $property->name,
            'price_raw' => $price_raw,
            'ipl'       => $ipl,
            'tax'       => $tax,
            'admin'     => $admin,
        ];

        $total_booking = $grossAmountOriginal;
        $installmentOptions = InstallmentService::plans();

        try {
            $transaction = \App\Models\Transaction::create([
                'user_id'                  => auth()->id(),
                'property_id'              => $id,
                'transaction_code'         => 'TR-WH-' . time(),
                'property_price'           => $price_base,
                'tax_amount'               => $tax,
                'gross_amount'             => $grossAmountOriginal,
                'total_payable'            => $grossAmountOriginal,
                'amount_paid'              => 0,
                'admin_fee'                => $admin,
                'payment_status'           => 'pending',
                'payment_type'             => $method,
                'installment_plan'         => $installmentPlan,
                'installment_period_months' => $installmentPeriodMonths,
                'installment_count'        => $installmentCount,
                'service_fee'              => $serviceFee,
                'installment_total'        => $installmentTotal,
                'paid_installments'        => 0,
            ]);

            if ($installmentPlan !== 'none' && $installmentCount > 1) {
                $dueDates = InstallmentService::getDueDates($installmentPlan, $installmentCount);
                foreach ($dueDates as $i => $dueDate) {
                    Installment::create([
                        'transaction_id'     => $transaction->id,
                        'installment_number' => $i + 1,
                        'amount'             => $installmentData['per_installment'] ?? $installmentTotal,
                        'paid_amount'        => 0,
                        'due_date'           => $dueDate,
                        'payment_status'     => 'pending',
                    ]);
                }
            }

            $payAmount = $installmentCount > 1 ? ($installmentData['per_installment'] ?? $installmentTotal) : $installmentTotal;

            $params = [
                'transaction_details' => [
                    'order_id'     => (string) $transaction->transaction_code,
                    'gross_amount' => (int) round($payAmount),
                ],
                'customer_details' => [
                    'first_name' => (string) (auth()->user()->name ?? 'User Pembeli'),
                    'email'      => (string) (auth()->user()->email ?? 'pembeli@test.com'),
                ],
            ];

            $snapToken = \Midtrans\Snap::getSnapToken($params);

            $transaction->load('installments');

            return view('pages.transaction', compact(
                'transaction', 'property', 'snapToken', 'project', 'total_booking',
                'installmentData', 'installmentPlan', 'installmentOptions'
            ));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Gagal menghubungkan ke gerbang pembayaran. Silakan coba lagi.');
        }
    }

    public function callback(Request $request)
    {
        $serverKey = config('services.midtrans.server_key');
        $hashed = hash("sha512", $request->order_id . $request->status_code . $request->gross_amount . $serverKey);

        if ($hashed !== $request->signature_key) {
            return response()->json(['status' => 'invalid signature'], 403);
        }

        $orderId = $request->order_id;

        if (str_contains($orderId, '-CICILAN-')) {
            $parts = explode('-CICILAN-', $orderId);
            $transactionCode = $parts[0];
            $installmentNumber = explode('-', $parts[1])[0];

            $transaction = Transaction::where('transaction_code', $transactionCode)->first();
            if (!$transaction) {
                return response()->json(['status' => 'transaction not found'], 404);
            }

            $installment = Installment::where('transaction_id', $transaction->id)
                ->where('installment_number', $installmentNumber)
                ->first();

            if (!$installment) {
                return response()->json(['status' => 'installment not found'], 404);
            }

            $transactionStatus = $request->transaction_status;
            $fraudStatus = $request->fraud_status;

            if (in_array($transactionStatus, ['capture', 'settlement'])) {
                if ($transactionStatus === 'capture' && $fraudStatus !== 'accept') {
                    return response()->json(['status' => 'fraud detected']);
                }
                $installment->update([
                    'payment_status' => 'success',
                    'paid_amount' => $installment->amount,
                    'paid_at' => now(),
                ]);

                $transaction->increment('paid_installments');
                $transaction->increment('amount_paid', $installment->amount);

                if ($transaction->paid_installments >= $transaction->installment_count) {
                    $transaction->update(['payment_status' => 'success']);
                    $property = Property::find($transaction->property_id);
                    if ($property && $property->status !== 'sold') {
                        $property->update(['status' => 'sold']);
                    }
                }
            } elseif (in_array($transactionStatus, ['deny', 'cancel', 'expire'])) {
                $installment->update(['payment_status' => 'failed']);
            } elseif ($transactionStatus == 'pending') {
                $installment->update(['payment_status' => 'pending']);
            }

            return response()->json(['status' => 'ok']);
        }

        $transaction = Transaction::where('transaction_code', $request->order_id)->first();
        if (!$transaction) {
            return response()->json(['status' => 'transaction not found'], 404);
        }

        $transactionStatus = $request->transaction_status;
        $fraudStatus = $request->fraud_status;

        if ($transactionStatus == 'capture') {
            if ($fraudStatus == 'accept') {
                $transaction->update([
                    'payment_status' => 'success',
                    'amount_paid' => $transaction->gross_amount,
                ]);
            if ($transaction->is_installment && $transaction->installment_count > 1) {
                $firstInstallment = $transaction->installments()->where('installment_number', 1)->first();
                if ($firstInstallment && $firstInstallment->payment_status !== 'success') {
                    $firstInstallment->update([
                        'payment_status' => 'success',
                        'paid_amount' => $firstInstallment->amount,
                        'paid_at' => now(),
                    ]);
                    $transaction->increment('paid_installments');
                }
            }
        }
    } elseif ($transactionStatus == 'settlement') {
        $transaction->update([
            'payment_status' => 'success',
            'amount_paid' => $transaction->gross_amount,
        ]);
        if ($transaction->is_installment && $transaction->installment_count > 1) {
            $firstInstallment = $transaction->installments()->where('installment_number', 1)->first();
            if ($firstInstallment && $firstInstallment->payment_status !== 'success') {
                $firstInstallment->update([
                    'payment_status' => 'success',
                    'paid_amount' => $firstInstallment->amount,
                    'paid_at' => now(),
                ]);
                $transaction->increment('paid_installments');
            }
        }
        } elseif (in_array($transactionStatus, ['deny', 'cancel', 'expire'])) {
            $transaction->update(['payment_status' => 'failed']);
        } elseif ($transactionStatus == 'pending') {
            $transaction->update(['payment_status' => 'pending']);
        }

        if ($transaction->payment_status === 'success' && !$transaction->is_installment) {
            $property = Property::find($transaction->property_id);
            if ($property && $property->status !== 'sold') {
                $property->update(['status' => 'sold']);
            }
        }

        return response()->json(['status' => 'ok']);
    }

    public function setSuccessInstantly($id)
{
    $transaction = \App\Models\Transaction::find($id);

    if (!$transaction) {
        return response()->json([
            'status'  => 'error',
            'message' => 'Transaksi tidak ditemukan.'
        ], 404);
    }

    if ($transaction->user_id !== Auth::id() && Auth::user()?->role !== 'admin') {
        return response()->json([
            'status'  => 'error',
            'message' => 'Anda tidak memiliki akses ke transaksi ini.'
        ], 403);
    }

    if ($transaction->is_installment && $transaction->installment_count > 1) {
        $firstInstallment = $transaction->installments()->where('installment_number', 1)->first();
        if ($firstInstallment && $firstInstallment->payment_status !== 'success') {
            $firstInstallment->update([
                'payment_status' => 'success',
                'paid_amount' => $firstInstallment->amount,
                'paid_at' => now(),
            ]);
            $transaction->increment('paid_installments');
            $transaction->increment('amount_paid', $firstInstallment->amount);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Cicilan pertama berhasil dikonfirmasi!'
        ]);
    }

    $transaction->update([
        'payment_status' => 'success',
        'amount_paid'    => $transaction->gross_amount
    ]);

    $property = \App\Models\Property::find($transaction->property_id);
    if ($property && $property->status !== 'sold') {
        $property->update(['status' => 'sold']);
    }

    return response()->json([
        'status'  => 'success',
        'message' => 'Pembayaran berhasil diperbarui langsung!'
    ]);
}

    public function invoice($id)
    {
        $transaction = Transaction::where('user_id', Auth::id())->findOrFail($id);
        $property = Property::find($transaction->property_id);
        return view('pages.invoice', compact('transaction', 'property'));
    }

    public function downloadInvoice($id)
{
    $transaction = Transaction::where('user_id', Auth::id())->findOrFail($id);
    $property = Property::find($transaction->property_id);

    if ($transaction->payment_status !== 'success') {
        return redirect()->back()->with('error', 'Invoice belum bisa diunduh.');
    }

    // SEKARANG KITA ARAHKAN KE TEMPLATE KHUSUS PDF YANG BARU
    $pdf = Pdf::loadView('exports.invoice_pdf', compact('transaction', 'property'));

    return $pdf->download($transaction->transaction_code . '.pdf');
    }

}
