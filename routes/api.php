<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\PrestationController;
use App\Http\Controllers\Api\PassageController;
use App\Http\Controllers\Api\PaiementController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\StatistiqueController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// ============================================
// Routes Publiques (Sans authentification)
// ============================================
Route::middleware('api')->group(function () {
    
    // Routes d'authentification
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login']);
    });

    // Route de santé (Health Check)
    Route::get('/health', function () {
        return response()->json([
            'success' => true,
            'message' => 'API Salon Management v1.1',
            'timestamp' => now()->toIso8601String(),
        ]);
    });
});

// ============================================
// Routes Protégées (Authentification requise)
// ============================================
Route::middleware(['auth:sanctum'])->group(function () {
    
    // ============================================
    // Routes Authentification (utilisateur connecté)
    // ============================================
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
    });

 // ============================================
// Routes Gestion des Utilisateurs (Admin uniquement)
// ============================================
Route::prefix('users')->group(function () {
    // Routes CRUD standard
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    Route::get('/{id}', [UserController::class, 'show']);
    Route::put('/{id}', [UserController::class, 'update']);
    Route::delete('/{id}', [UserController::class, 'destroy']);
    Route::post('/{id}/toggle-actif', [UserController::class, 'toggleActif']);
    
    // Route pour obtenir la liste des coiffeurs (spécifique)
    Route::get('/coiffeurs/liste', [UserController::class, 'coiffeurs']);
    
    // Routes spécifiques aux coiffeurs
    Route::get('/coiffeurs/{id}/statistiques', [UserController::class, 'statistiquesCoiffeur']);
    Route::post('/coiffeurs/{id}/prestations', [UserController::class, 'associerPrestation']);
    Route::delete('/coiffeurs/{coiffeurId}/prestations/{prestationId}', [UserController::class, 'detacherPrestation']);
});
    
    // ============================================
    
    Route::prefix('clients')->group(function () {
        // IMPORTANT: Les routes spécifiques DOIVENT être avant les routes avec paramètres {id}
        Route::get('/generate-code', [ClientController::class, 'generateCode']);
        Route::get('/search/{phone}', [ClientController::class, 'searchByPhone']);
        
        // Routes CRUD standards
        Route::get('/', [ClientController::class, 'index']);
        Route::post('/', [ClientController::class, 'store']);
        Route::get('/{id}', [ClientController::class, 'show']);
        Route::put('/{id}', [ClientController::class, 'update']);
        Route::delete('/{id}', [ClientController::class, 'destroy']);
        Route::get('/{id}/historique', [ClientController::class, 'historique']);
    });

    // ============================================
    // Routes Prestations
    // ============================================
    Route::prefix('prestations')->group(function () {
        Route::get('/', [PrestationController::class, 'index']);
        Route::post('/', [PrestationController::class, 'store']);
        Route::get('/{id}', [PrestationController::class, 'show']);
        Route::put('/{id}', [PrestationController::class, 'update']);
        Route::delete('/{id}', [PrestationController::class, 'destroy']);
        Route::post('/{id}/toggle-actif', [PrestationController::class, 'toggleActif']);

            // Routes pour la gestion des coiffeurs
    Route::get('/{id}/coiffeurs', [PrestationController::class, 'getCoiffeurs']);
    Route::post('/{id}/coiffeurs/attach', [PrestationController::class, 'attachCoiffeur']);
    Route::delete('/{id}/coiffeurs/{coiffeurId}', [PrestationController::class, 'detachCoiffeur']);
    Route::get('/coiffeur/{coiffeurId}', [PrestationController::class, 'byCoiffeur']);

        Route::get('/stats/populaires', [PrestationController::class, 'populaires']);
    });

    // ============================================
    // Routes Passages
    // ============================================
    Route::prefix('passages')->group(function () {
        Route::get('/', [PassageController::class, 'index']);
        Route::post('/', [PassageController::class, 'store']);
        Route::get('/{id}', [PassageController::class, 'show']);
        Route::put('/{id}', [PassageController::class, 'update']);
        Route::delete('/{id}', [PassageController::class, 'destroy']);
        Route::get('/client/{clientId}', [PassageController::class, 'parClient']);
        Route::get('/client/{clientId}/check-fidelite', [PassageController::class, 'checkFidelite']);
    });

    // ============================================
    // Routes Paiements
    // ============================================
    Route::prefix('paiements')->group(function () {
        Route::get('/', [PaiementController::class, 'index']);
        Route::post('/', [PaiementController::class, 'store']);
        Route::get('/{id}', [PaiementController::class, 'show']);
        Route::put('/{id}', [PaiementController::class, 'update']);
        Route::get('/{id}/recu', [PaiementController::class, 'genererRecu']);
        Route::delete('/{id}', [PaiementController::class, 'destroy']);
        Route::get('/{id}/recu/data', [PaiementController::class, 'donneesRecu']);
        Route::post('/{id}/annuler', [PaiementController::class, 'annuler']);
    });

    // ============================================
    // Routes Synchronisation (Offline-First)
    // ============================================
    Route::prefix('sync')->group(function () {
        Route::post('/batch', [SyncController::class, 'batch']);
        Route::get('/status', [SyncController::class, 'status']);
        Route::get('/pull', [SyncController::class, 'pull']);
    });

    // ============================================
    // Routes Statistiques & Bilans
    // ============================================
    Route::prefix('statistiques')->group(function () {
        Route::get('/journalier', [StatistiqueController::class, 'journalier']);
        Route::get('/periode', [StatistiqueController::class, 'periode']);
        Route::get('/prestations', [StatistiqueController::class, 'prestations']);
        Route::get('/fidelite', [StatistiqueController::class, 'fidelite']);
        Route::get('/dashboard', [StatistiqueController::class, 'dashboard']);

        Route::get('coiffeurs', [StatistiqueController::class, 'coiffeurs']);
    });
});
