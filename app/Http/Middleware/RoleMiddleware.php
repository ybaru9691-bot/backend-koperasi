<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$roles
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $userRole = strtolower(trim((string) ($user->role ?? '')));
        $allowedRoles = array_map(fn($r) => strtolower(trim($r)), $roles);

        // Jika manager diminta, kita izinkan 'manager', 'ketua', 'pengurus'
        if (in_array('manager', $allowedRoles)) {
            $allowedRoles = array_unique(array_merge($allowedRoles, ['ketua', 'pengurus']));
        }

        if (!in_array($userRole, $allowedRoles)) {
            $isManagerCheck = in_array('manager', array_map('strtolower', $roles));
            $msg = $isManagerCheck
                ? 'Hanya Manajer/Ketua yang berhak memberikan persetujuan pinjaman.'
                : 'Akses ditolak: Anda tidak memiliki wewenang untuk mengakses aksi ini.';

            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => $msg,
            ], 403);
        }

        return $next($request);
    }
}