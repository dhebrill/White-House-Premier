<?php

namespace App\Http\Controllers;

use App\Models\Installment;
use App\Services\InstallmentService;
use Illuminate\Http\Request;
use Midtrans\Config;
use Midtrans\Snap;

class InstallmentController extends Controller
{
    public function __construct()
    {
        Config::$serverKey = config('services.midtrans.server_key');
        Config::$isProduction = config('services.midtrans.is_production');
        Config::$isSanitized = config('services.midtrans.is_sanitized');
        Config::$is3ds = config('services.midtrans.is_3ds');
    }

    public function payInstallment($installmentId)
    {
        $installment = Installment::with('transaction')->findOrFail($installmentId);

        if ($installment->transaction->user_id !== auth()->id()) {
            abort(403);
        }

        if ($installment->payment_status === 'success') {
            return redirect()->back()->with('error', 'Cicilan ini sudah lunas.');
        }

        $params = [
            'transaction_details' => [
                'order_id' => $installment->transaction->transaction_code . '-CICILAN-' . $installment->installment_number . '-' . time(),
                'gross_amount' => (int) round($installment->amount),
            ],
            'customer_details' => [
                'first_name' => auth()->user()->name ?? 'User',
                'email' => auth()->user()->email ?? 'user@test.com',
            ],
        ];

        try {
            $snapToken = Snap::getSnapToken($params);
            $installment->update(['snap_token' => $snapToken]);

            return view('pages.installment-checkout', compact('installment', 'snapToken'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Gagal terhubung ke pembayaran. Silakan coba lagi.');
        }
    }

    public function setSuccessInstantly($installmentId)
    {
        $installment = Installment::with('transaction')->findOrFail($installmentId);

        if ($installment->transaction->user_id !== auth()->id() && auth()->user()?->role !== 'admin') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Anda tidak memiliki akses ke cicilan ini.'
            ], 403);
        }

        if ($installment->payment_status === 'success') {
            return response()->json([
                'status'  => 'success',
                'message' => 'Cicilan ini sudah lunas.'
            ]);
        }

        $installment->update([
            'payment_status' => 'success',
            'paid_amount'     => $installment->amount,
            'paid_at'         => now(),
        ]);

        $transaction = $installment->transaction;
        $transaction->increment('paid_installments');
        $transaction->increment('amount_paid', $installment->amount);

        if ($transaction->paid_installments >= $transaction->installment_count) {
            $transaction->update(['payment_status' => 'success']);
            $property = \App\Models\Property::find($transaction->property_id);
            if ($property && $property->status !== 'sold') {
                $property->update(['status' => 'sold']);
            }
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Cicilan berhasil dikonfirmasi!'
        ]);
    }

    public static function getInstallmentOptions(float $totalAmount): array
    {
        $options = [];
        foreach (InstallmentService::plans() as $key => $plan) {
            $options[$key] = InstallmentService::calculate($totalAmount, $key);
        }
        return $options;
    }
}
