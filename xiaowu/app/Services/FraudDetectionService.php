<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class FraudDetectionService
{
    public function evaluateAndRecord(array $payload): array
    {
        $score = 0;
        $reasons = [];
        $amount = (float) ($payload['amount'] ?? 0);

        if ($amount >= 5000) {
            $score += 40;
            $reasons[] = 'high_amount';
        } elseif ($amount >= 1000) {
            $score += 20;
            $reasons[] = 'medium_amount';
        }

        $walletId = $payload['wallet_id'] ?? null;

        if ($walletId) {
            $recentCount = DB::table('transaction_ledgers')
                ->where('wallet_id', $walletId)
                ->where('created_at', '>=', now()->subHour())
                ->count();

            if ($recentCount >= 10) {
                $score += 40;
                $reasons[] = 'high_velocity';
            } elseif ($recentCount >= 5) {
                $score += 20;
                $reasons[] = 'medium_velocity';
            }

            $wallet = DB::table('wallets')
                ->select(['status_id', 'frozen_at'])
                ->where('id', $walletId)
                ->first();

            if ($wallet && $wallet->frozen_at) {
                $score += 50;
                $reasons[] = 'wallet_frozen';
            }
        }

        $riskLevel = $score >= 60 ? 'high' : ($score >= 30 ? 'medium' : 'low');
        $alert = null;

        if ($score >= 50) {
            $alertId = DB::table('fraud_alerts')->insertGetId([
                'transaction_ledger_id' => $payload['transaction_ledger_id'] ?? null,
                'wallet_id' => $walletId,
                'related_wallet_id' => $payload['related_wallet_id'] ?? null,
                'risk_score' => $score,
                'risk_level' => $riskLevel,
                'status' => 'open',
                'reasons' => $reasons ? json_encode($reasons) : null,
                'external_provider' => $payload['external_provider'] ?? null,
                'external_reference' => $payload['external_reference'] ?? null,
                'external_score' => $payload['external_score'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $alert = [
                'id' => $alertId,
                'risk_score' => $score,
                'risk_level' => $riskLevel,
                'reasons' => $reasons,
            ];
        }

        return [
            'risk_score' => $score,
            'risk_level' => $riskLevel,
            'reasons' => $reasons,
            'alert' => $alert,
        ];
    }
}
