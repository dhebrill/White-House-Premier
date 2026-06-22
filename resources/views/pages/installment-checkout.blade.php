@extends('layouts.app')

@section('title', 'Bayar Cicilan')

@section('content')
<div class="min-h-screen bg-gray-50 py-12">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">

        <div class="bg-white rounded-lg shadow-lg overflow-hidden">
            <div class="bg-purple-600 px-6 py-4">
                <h1 class="text-2xl font-bold text-white">Pembayaran Cicilan</h1>
            </div>

            <div class="p-6">
                <div class="space-y-4">
                    <div class="bg-purple-50 rounded-lg p-4 border border-purple-100">
                        <div class="flex justify-between text-sm mb-2">
                            <span class="text-gray-600">Transaksi:</span>
                            <span class="font-bold text-purple-700">{{ $installment->transaction->transaction_code }}</span>
                        </div>
                        <div class="flex justify-between text-sm mb-2">
                            <span class="text-gray-600">Cicilan Ke-:</span>
                            <span class="font-bold text-gray-800">{{ $installment->installment_number }} / {{ $installment->transaction->installment_count }}</span>
                        </div>
                        <div class="flex justify-between text-sm mb-2">
                            <span class="text-gray-600">Jatuh Tempo:</span>
                            <span class="font-medium text-gray-800">{{ $installment->due_date->format('d M Y') }}</span>
                        </div>
                        <hr class="my-3 border-purple-200">
                        <div class="flex justify-between text-lg font-bold">
                            <span>Jumlah Dibayar:</span>
                            <span class="text-purple-700">Rp {{ number_format($installment->amount, 0, ',', '.') }}</span>
                        </div>
                    </div>

                    <div class="flex flex-col items-center p-6 bg-gray-50 rounded-lg border border-gray-200">
                        <p class="text-sm text-gray-500 text-center mb-4">
                            Klik tombol di bawah untuk melanjutkan pembayaran cicilan.
                        </p>
                        <button id="pay-button" class="w-full bg-purple-600 text-white py-3 px-4 rounded-md hover:bg-purple-700 transition font-medium shadow-md">
                            Bayar Cicilan Sekarang
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript" src="https://app.sandbox.midtrans.com/snap/snap.js" data-client-key="Mid-client-PGRfNg1aUYVHwFV7"></script>

<script type="text/javascript">
    window.addEventListener('DOMContentLoaded', function() {
        triggerSnapPay();
    });

    document.getElementById('pay-button')?.addEventListener('click', function(e) {
        e.preventDefault();
        triggerSnapPay();
    });

    function triggerSnapPay() {
        const token = '{{ $snapToken }}';
        if (!token || token === '') {
            alert("Error: Token pembayaran tidak ditemukan.");
            return;
        }
        window.snap.pay(token, {
            onSuccess: function(result) {
                konfirmasiPembayaran();
            },
            onPending: function(result) {
                konfirmasiPembayaran();
            },
            onError: function(result) {
                alert("Pembayaran cicilan gagal.");
            }
        });
    }

    function konfirmasiPembayaran() {
        fetch("{{ url('/installment/set-success') }}/{{ $installment->id }}", {
            method: "POST",
            headers: {
                "X-CSRF-TOKEN": "{{ csrf_token() }}",
                "Content-Type": "application/json",
                "Accept": "application/json"
            }
        })
        .then(response => response.json())
        .then(data => {
            alert("Pembayaran cicilan berhasil dikonfirmasi!");
            window.location.href = "{{ route('dashboard') }}";
        })
        .catch(error => {
            console.error("Error:", error);
            window.location.href = "{{ route('dashboard') }}";
        });
    }
</script>
@endsection
