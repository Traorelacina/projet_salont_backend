<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Paiement;
use App\Models\Passage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Barryvdh\DomPDF\Facade\Pdf;

class PaiementController extends Controller
{
    /**
     * Liste tous les paiements.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Paiement::with(['passage.client', 'passage.prestations']);

        // Filtrer par date
        if ($request->has('date')) {
            $query->byDate($request->date);
        }

        // Filtrer par période
        if ($request->has('date_debut') && $request->has('date_fin')) {
            $query->byPeriod($request->date_debut, $request->date_fin);
        }

        // Filtrer par statut
        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }

        // Tri
        $query->orderBy('date_paiement', 'desc');

        // Pagination
        $perPage = $request->get('per_page', 20);
        $paiements = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $paiements,
        ]);
    }

    /**
     * Créer un nouveau paiement.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'passage_id' => 'required|exists:passages,id',
            'montant_paye' => 'required|numeric|min:0',
            'mode_paiement' => 'required|in:especes,mobile_money,carte,autre',
            'notes' => 'nullable|string',
            'date_paiement' => 'nullable|date',
            'device_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $passage = Passage::with(['prestations', 'client'])->findOrFail($request->passage_id);

        // Vérifier si un paiement existe déjà pour ce passage
        if ($passage->paiement) {
            return response()->json([
                'success' => false,
                'message' => 'Un paiement existe déjà pour ce passage',
            ], 422);
        }

        $montantTotal = $passage->montant_total;

        $paiement = Paiement::create([
            'passage_id' => $passage->id,
            'montant_total' => $montantTotal,
            'montant_paye' => $request->montant_paye,
            'mode_paiement' => $request->mode_paiement,
            'statut' => 'valide',
            'notes' => $request->notes,
            'date_paiement' => $request->date_paiement ?? now(),
            'device_id' => $request->device_id,
            'synced_at' => now(),
        ]);

        $paiement->load(['passage.client', 'passage.prestations']);

        return response()->json([
            'success' => true,
            'message' => 'Paiement enregistré avec succès',
            'data' => $paiement,
        ], 201);
    }

    /**
     * Afficher un paiement spécifique.
     */
    public function show(int $id): JsonResponse
    {
        $paiement = Paiement::with(['passage.client', 'passage.prestations'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $paiement,
        ]);
    }

    /**
     * Mettre à jour un paiement.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $paiement = Paiement::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'montant_paye' => 'sometimes|required|numeric|min:0',
            'mode_paiement' => 'sometimes|required|in:especes,mobile_money,carte,autre',
            'statut' => 'sometimes|required|in:en_attente,valide,annule',
            'notes' => 'nullable|string',
            'device_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $paiement->update($request->only([
            'montant_paye', 'mode_paiement', 'statut', 'notes', 'device_id'
        ]));
        $paiement->update(['synced_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Paiement mis à jour avec succès',
            'data' => $paiement->load(['passage.client', 'passage.prestations']),
        ]);
    }

    /**
     * Supprimer un paiement.
     * 
     * NOUVEAU - Cette méthode permet de supprimer définitivement un paiement
     */
    public function destroy(int $id): JsonResponse
    {
        $paiement = Paiement::findOrFail($id);

        $paiement->delete();

        return response()->json([
            'success' => true,
            'message' => 'Paiement supprimé avec succès',
        ]);
    }

    /**
     * Générer le reçu PDF.
     */
    public function genererRecu(int $id)
    {
        $paiement = Paiement::with(['passage.client', 'passage.prestations'])
            ->findOrFail($id);

        $data = [
            'paiement' => $paiement,
            'passage' => $paiement->passage,
            'client' => $paiement->passage->client,
            'prestations' => $paiement->passage->prestations,
            'salon' => [
                'nom' => config('app.salon_name', 'Salon de Coiffure'),
                'adresse' => config('app.salon_address', 'Abidjan, Côte d\'Ivoire'),
                'telephone' => config('app.salon_phone', '+225 00 00 00 00'),
            ],
        ];

        $pdf = Pdf::loadView('recu', $data);
        
        return $pdf->download('recu-' . $paiement->numero_recu . '.pdf');
    }

    /**
     * Obtenir les données du reçu (pour affichage).
     */
    public function donneesRecu(int $id): JsonResponse
    {
        $paiement = Paiement::with(['passage.client', 'passage.prestations'])
            ->findOrFail($id);

        $data = [
            'numero_recu' => $paiement->numero_recu,
            'date' => $paiement->date_paiement->format('d/m/Y H:i'),
            'client' => [
                'nom_complet' => $paiement->passage->client->nom_complet,
                'telephone' => $paiement->passage->client->telephone,
            ],
            'prestations' => $paiement->passage->prestations->map(function($prestation) {
                return [
                    'libelle' => $prestation->libelle,
                    'quantite' => $prestation->pivot->quantite,
                    'prix_unitaire' => $prestation->pivot->prix_applique,
                    'prix_total' => $prestation->pivot->prix_applique * $prestation->pivot->quantite,
                ];
            }),
            'montant_total' => $paiement->montant_total,
            'montant_paye' => $paiement->montant_paye,
            'mode_paiement' => $paiement->mode_paiement,
            'est_gratuit' => $paiement->passage->est_gratuit,
            'numero_passage' => $paiement->passage->numero_passage,
            'salon' => [
                'nom' => config('app.salon_name', 'Salon de Coiffure'),
                'adresse' => config('app.salon_address', 'Abidjan, Côte d\'Ivoire'),
                'telephone' => config('app.salon_phone', '+225 00 00 00 00'),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Annuler un paiement.
     */
    public function annuler(int $id): JsonResponse
    {
        $paiement = Paiement::findOrFail($id);
        
        if ($paiement->statut === 'annule') {
            return response()->json([
                'success' => false,
                'message' => 'Ce paiement est déjà annulé',
            ], 422);
        }

        $paiement->update(['statut' => 'annule']);

        return response()->json([
            'success' => true,
            'message' => 'Paiement annulé avec succès',
            'data' => $paiement,
        ]);
    }
}