<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    private function adminGuard(Request $request): bool
    {
        $admin = $request->user();
        return $admin && $admin->role === 'admin';
    }

    public function index(Request $request)
    {
        if (! $this->adminGuard($request)) {
            return response()->json(['message' => 'Unauthorized: Only administrators can access this endpoint.'], 403);
        }

        $ttl = 60; // 1 minute cache
        $data = Cache::remember('admin:dashboard:v1', $ttl, function () {
            return $this->buildDashboard();
        });

        return response()->json(['message' => 'Dashboard data retrieved successfully', 'data' => $data], 200);
    }

    private function buildDashboard(): array
    {
        $now = now();
        $startOfThisMonth = $now->copy()->startOfMonth();
        $startOfLastMonth = $now->copy()->subMonth()->startOfMonth();
        $endOfLastMonth   = $startOfThisMonth->copy()->subSecond();

        // ── Run all heavy aggregates in parallel via a single multi-query pass ──

        // 1. User stats
        $totalUsers = DB::table('users')->whereNull('deleted_at')->count();

        $usersThisMonth = DB::table('users')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $startOfThisMonth)
            ->count();

        $usersLastMonth = DB::table('users')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])
            ->count();

        $activeUsers = DB::table('users')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->count();

        // 2. Listing stats
        $totalListings = DB::table('products')->whereNull('deleted_at')->count();

        $listingsThisMonth = DB::table('products')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $startOfThisMonth)
            ->count();

        $listingsLastMonth = DB::table('products')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])
            ->count();

        // 3. Transactions (sold products as proxy)
        $totalTransactions = DB::table('products')
            ->whereNull('deleted_at')
            ->where('status', 'sold')
            ->count();

        $transactionsThisMonth = DB::table('products')
            ->whereNull('deleted_at')
            ->where('status', 'sold')
            ->where('updated_at', '>=', $startOfThisMonth)
            ->count();

        $transactionsLastMonth = DB::table('products')
            ->whereNull('deleted_at')
            ->where('status', 'sold')
            ->whereBetween('updated_at', [$startOfLastMonth, $endOfLastMonth])
            ->count();

        // 4. Total value exchanged (sum of sold products)
        $totalValueThisMonth = (float) DB::table('products')
            ->whereNull('deleted_at')
            ->where('status', 'sold')
            ->where('updated_at', '>=', $startOfThisMonth)
            ->sum('price');

        $totalValueLastMonth = (float) DB::table('products')
            ->whereNull('deleted_at')
            ->where('status', 'sold')
            ->whereBetween('updated_at', [$startOfLastMonth, $endOfLastMonth])
            ->sum('price');

        $totalValueAll = (float) DB::table('products')
            ->whereNull('deleted_at')
            ->where('status', 'sold')
            ->sum('price');

        // 5. Universities with location data
        $universities = DB::table('universities')
            ->select(['id', 'name', 'address', 'latitude', 'longitude'])
            ->orderBy('name')
            ->get()
            ->map(fn ($u) => [
                'id'        => $u->id,
                'name'      => $u->name,
                'address'   => $u->address,
                'latitude'  => $u->latitude,
                'longitude' => $u->longitude,
            ])
            ->values()
            ->all();

        // 6. Universities overview: user count per university (single query)
        $uniOverview = DB::table('universities')
            ->leftJoin('dormitories', 'dormitories.university_id', '=', 'universities.id')
            ->leftJoin('users', function ($join) {
                $join->on('users.dormitory_id', '=', 'dormitories.id')
                     ->whereNull('users.deleted_at');
            })
            ->select([
                'universities.id',
                'universities.name',
                DB::raw('COUNT(DISTINCT users.id) as users_count'),
            ])
            ->groupBy('universities.id', 'universities.name')
            ->orderBy('universities.name')
            ->get()
            ->map(fn ($r) => [
                'university_id'   => $r->id,
                'university_name' => $r->name,
                'users_count'     => (int) $r->users_count,
            ])
            ->values()
            ->all();

        // 7. Monthly chart data for the current year (users registered + transaction amount per month)
        $currentYear = $now->year;
        $monthNames  = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

        $usersByMonth = DB::table('users')
            ->whereNull('deleted_at')
            ->whereYear('created_at', $currentYear)
            ->selectRaw('MONTH(created_at) as month, COUNT(*) as total')
            ->groupByRaw('MONTH(created_at)')
            ->pluck('total', 'month');

        $valueByMonth = DB::table('products')
            ->whereNull('deleted_at')
            ->where('status', 'sold')
            ->whereYear('updated_at', $currentYear)
            ->selectRaw('MONTH(updated_at) as month, SUM(price) as total')
            ->groupByRaw('MONTH(updated_at)')
            ->pluck('total', 'month');

        $chartData = [];
        for ($m = 1; $m <= 12; $m++) {
            $chartData[] = [
                'name'                => $monthNames[$m - 1],
                'users'               => (int) ($usersByMonth[$m] ?? 0),
                'transactions_amount' => round((float) ($valueByMonth[$m] ?? 0), 2),
            ];
        }

        // 8. Recent 5 users
        $recentUsers = DB::table('users')
            ->leftJoin('dormitories', 'users.dormitory_id', '=', 'dormitories.id')
            ->leftJoin('universities', 'dormitories.university_id', '=', 'universities.id')
            ->whereNull('users.deleted_at')
            ->orderByDesc('users.created_at')
            ->limit(5)
            ->select([
                'users.id',
                'users.full_name',
                'users.email',
                'users.username',
                'users.profile_picture',
                'users.status',
                'users.created_at',
                'universities.name as university_name',
            ])
            ->get()
            ->map(fn ($u) => [
                'id'              => $u->id,
                'full_name'       => $u->full_name,
                'email'           => $u->email,
                'username'        => $u->username,
                'profile_picture' => $u->profile_picture,
                'status'          => $u->status,
                'university'      => $u->university_name,
                'joined_at'       => $u->created_at,
            ])
            ->values()
            ->all();

        return [
            'stats' => [
                'total_users' => [
                    'value'      => $totalUsers,
                    'change_pct' => $this->pct($usersLastMonth, $usersThisMonth),
                    'trend'      => $this->trend($usersLastMonth, $usersThisMonth),
                ],
                'active_listings' => [
                    'value'      => $totalListings,
                    'change_pct' => $this->pct($listingsLastMonth, $listingsThisMonth),
                    'trend'      => $this->trend($listingsLastMonth, $listingsThisMonth),
                ],
                'total_value_exchanged' => [
                    'value'      => round($totalValueAll, 2),
                    'change_pct' => $this->pct($totalValueLastMonth, $totalValueThisMonth),
                    'trend'      => $this->trend($totalValueLastMonth, $totalValueThisMonth),
                ],
                'transactions' => [
                    'value'      => $totalTransactions,
                    'change_pct' => $this->pct($transactionsLastMonth, $transactionsThisMonth),
                    'trend'      => $this->trend($transactionsLastMonth, $transactionsThisMonth),
                ],
                'active_users'    => $activeUsers,
                'new_users_month' => $usersThisMonth,
            ],
            'universities'          => $universities,
            'universities_overview' => $uniOverview,
            'chart_data'            => $chartData,
            'recent_users'          => $recentUsers,
        ];
    }

    private function pct(float|int $prev, float|int $curr): float
    {
        if ($prev == 0) {
            return $curr > 0 ? 100.0 : 0.0;
        }
        return round((($curr - $prev) / $prev) * 100, 2);
    }

    private function trend(float|int $prev, float|int $curr): string
    {
        if ($curr > $prev) return 'up';
        if ($curr < $prev) return 'down';
        return 'stable';
    }
}
