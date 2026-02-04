<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Passage;
use App\Models\Client;
use App\Models\Prestation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PassageController extends Controller
{
    /**
     * Liste tous les passages.
     */
    public function index(Request $request): JsonResponse
    {
        // Charger les prestations avec leur coiffeur via la table pivot
        $query = Passage::with([
            'client', 
            'prestations' => function($query) {
                $query->withPivot('coiffeur_id');
            },
            'paiement'
        ]);

        // Filtrer par client
        if ($request->has('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        // Filtrer par coiffeur
        if ($request->has('coiffeur_id')) {
            $query->whereHas('prestations', function($q) use ($request) {
                $q->where('passage_prestation.coiffeur_id', $request->coiffeur_id);
            });
        }

        // Filtrer par date
        if ($request->has('date')) {
            $query->byDate($request->date);
        }

        // Filtrer par période
        if ($request->has('date_debut') && $request->has('date_fin')) {
            $query->byPeriod($request->date_debut, $request->date_fin);
        }

        // Filtrer les passages gratuits
        if ($request->has('gratuit')) {
            $query->where('est_gratuit', $request->boolean('gratuit'));
        }

        // Tri
        $query->orderBy('date_passage', 'desc');

        // Pagination
        $perPage = $request->get('per_page', 20);
        $passages = $query->paginate($perPage);

        // Charger les coiffeurs pour chaque prestation
        $passages->getCollection()->transform(function ($passage) {
            $passage->prestations->each(function ($prestation) {
                if ($prestation->pivot->coiffeur_id) {
                    $prestation->coiffeur = User::find($prestation->pivot->coiffeur_id);
                }
            });
            return $passage;
        });

        return response()->json([
            'success' => true,
            'data' => $passages,
        ]);
    }

    /**
     * Créer un nouveau passage.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:clients,id',
            'prestations' => 'required|array|min:1',
            'prestations.*.id' => 'required|exists:prestations,id',
            'prestations.*.quantite' => 'integer|min:1',
            'prestations.*.coiffeur_id' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
            'date_passage' => 'nullable|date',
            'device_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Vérifier que les coiffeurs sont bien des coiffeurs
        foreach ($request->prestations as $prestationData) {
            if (isset($prestationData['coiffeur_id'])) {
                $coiffeur = User::find($prestationData['coiffeur_id']);
                if (!$coiffeur || $coiffeur->role !== 'coiffeur') {
                    return response()->json([
                        'success' => false,
                        'message' => 'L\'utilisateur spécifié n\'est pas un coiffeur',
                    ], 422);
                }
            }
        }

        try {
            DB::beginTransaction();

            $client = Client::findOrFail($request->client_id);

            // Créer le passage
            $passage = Passage::create([
                'client_id' => $client->id,
                'notes' => $request->notes,
                'date_passage' => $request->date_passage ?? now(),
                'device_id' => $request->device_id,
                'synced_at' => now(),
            ]);

            // Attacher les prestations avec les coiffeurs
            foreach ($request->prestations as $prestationData) {
                $prestation = Prestation::findOrFail($prestationData['id']);
                $quantite = $prestationData['quantite'] ?? 1;
                $coiffeurId = $prestationData['coiffeur_id'] ?? null;

                $passage->prestations()->attach($prestation->id, [
                    'prix_applique' => $prestation->prix,
                    'quantite' => $quantite,
                    'coiffeur_id' => $coiffeurId,
                ]);
            }

            // Recharger avec les relations
            $passage->load([
                'client', 
                'prestations' => function($query) {
                    $query->withPivot('coiffeur_id');
                },
                'paiement'
            ]);

            // Charger les coiffeurs
            $passage->prestations->each(function ($prestation) {
                if ($prestation->pivot->coiffeur_id) {
                    $prestation->coiffeur = User::find($prestation->pivot->coiffeur_id);
                }
            });

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Passage créé avec succès',
                'data' => [
                    'passage' => $passage,
                    'est_gratuit' => $passage->est_gratuit,
                    'montant_total' => $passage->montant_total,
                    'montant_theorique' => $passage->montant_theorique,
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erreur lors de la création du passage', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du passage',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Afficher un passage spécifique.
     */
    public function show(int $id): JsonResponse
    {
        $passage = Passage::with([
            'client', 
            'prestations' => function($query) {
                $query->withPivot('coiffeur_id');
            },
            'paiement'
        ])->findOrFail($id);

        // Charger les coiffeurs
        $passage->prestations->each(function ($prestation) {
            if ($prestation->pivot->coiffeur_id) {
                $prestation->coiffeur = User::find($prestation->pivot->coiffeur_id);
            }
        });

        return response()->json([
            'success' => true,
            'data' => $passage,
        ]);
    }

    /**
     * Mettre à jour un passage.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $passage = Passage::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string',
            'date_passage' => 'nullable|date',
            'device_id' => 'nullable|string',
            'prestations' => 'sometimes|array|min:1',
            'prestations.*.id' => 'required_with:prestations|exists:prestations,id',
            'prestations.*.quantite' => 'integer|min:1',
            'prestations.*.coiffeur_id' => 'nullable|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Mettre à jour les champs de base
            $passage->update($request->only(['notes', 'date_passage', 'device_id']));
            $passage->update(['synced_at' => now()]);

            // Si les prestations sont fournies, mettre à jour la relation
            if ($request->has('prestations')) {
                // Détacher les anciennes prestations
                $passage->prestations()->detach();

                // Attacher les nouvelles
                foreach ($request->prestations as $prestationData) {
                    $prestation = Prestation::findOrFail($prestationData['id']);
                    $quantite = $prestationData['quantite'] ?? 1;
                    $coiffeurId = $prestationData['coiffeur_id'] ?? null;

                    // Vérifier que le coiffeur est bien un coiffeur
                    if ($coiffeurId) {
                        $coiffeur = User::find($coiffeurId);
                        if (!$coiffeur || $coiffeur->role !== 'coiffeur') {
                            DB::rollBack();
                            return response()->json([
                                'success' => false,
                                'message' => 'L\'utilisateur spécifié n\'est pas un coiffeur',
                            ], 422);
                        }
                    }

                    $passage->prestations()->attach($prestation->id, [
                        'prix_applique' => $prestation->prix,
                        'quantite' => $quantite,
                        'coiffeur_id' => $coiffeurId,
                    ]);
                }
            }

            DB::commit();

            // Recharger avec les relations
            $passage->load([
                'client', 
                'prestations' => function($query) {
                    $query->withPivot('coiffeur_id');
                },
                'paiement'
            ]);

            // Charger les coiffeurs
            $passage->prestations->each(function ($prestation) {
                if ($prestation->pivot->coiffeur_id) {
                    $prestation->coiffeur = User::find($prestation->pivot->coiffeur_id);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Passage mis à jour avec succès',
                'data' => $passage,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erreur lors de la mise à jour du passage', [
                'passage_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du passage',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Supprimer un passage.
     * IMPORTANT : Cette méthode réduit le nombre de passages du client ET recalcule les numéros de passage
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            DB::beginTransaction();

            $passage = Passage::with('client')->findOrFail($id);
            $client = $passage->client;
            $numeroPassageSuprime = $passage->numero_passage;

            // Supprimer le passage
            $passage->delete();

            // 1. Recalculer le nombre de passages du client
            $nombrePassages = Passage::where('client_id', $client->id)->count();
            $client->update(['nombre_passages' => $nombrePassages]);

            // 2. Recalculer les numéros de passage pour tous les passages restants du client
            // Récupérer tous les passages du client ordonnés par date
            $passagesRestants = Passage::where('client_id', $client->id)
                ->orderBy('date_passage', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            // Réassigner les numéros de passage dans l'ordre
            $numeroActuel = 1;
            foreach ($passagesRestants as $p) {
                $p->update(['numero_passage' => $numeroActuel]);
                $numeroActuel++;
            }

            // Recharger le client pour avoir les données à jour
            $client->refresh();

            DB::commit();

            Log::info('Passage supprimé avec succès', [
                'passage_id' => $id,
                'numero_passage_supprime' => $numeroPassageSuprime,
                'client_id' => $client->id,
                'nouveau_nombre_passages' => $nombrePassages,
                'passages_renumerotes' => $passagesRestants->count(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Passage supprimé avec succès',
                'data' => [
                    'client_id' => $client->id,
                    'numero_passage_supprime' => $numeroPassageSuprime,
                    'nouveau_nombre_passages' => $nombrePassages,
                    'passages_renumerotes' => $passagesRestants->count(),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erreur lors de la suppression du passage', [
                'passage_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du passage',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtenir les passages d'un client.
     */
    public function parClient(int $clientId): JsonResponse
    {
        $passages = Passage::with([
            'prestations' => function($query) {
                $query->withPivot('coiffeur_id');
            },
            'paiement'
        ])
        ->where('client_id', $clientId)
        ->orderBy('date_passage', 'desc')
        ->paginate(20);

        // Charger les coiffeurs
        $passages->getCollection()->transform(function ($passage) {
            $passage->prestations->each(function ($prestation) {
                if ($prestation->pivot->coiffeur_id) {
                    $prestation->coiffeur = User::find($prestation->pivot->coiffeur_id);
                }
            });
            return $passage;
        });

        return response()->json([
            'success' => true,
            'data' => $passages,
        ]);
    }

    /**
     * Obtenir les passages réalisés par un coiffeur.
     */
    public function parCoiffeur(Request $request, int $coiffeurId): JsonResponse
    {
        $coiffeur = User::findOrFail($coiffeurId);

        if ($coiffeur->role !== 'coiffeur') {
            return response()->json([
                'success' => false,
                'message' => 'Cet utilisateur n\'est pas un coiffeur',
            ], 400);
        }

        $query = Passage::with([
            'client', 
            'prestations' => function($query) {
                $query->withPivot('coiffeur_id');
            },
            'paiement'
        ])
        ->whereHas('prestations', function($q) use ($coiffeurId) {
            $q->where('passage_prestation.coiffeur_id', $coiffeurId);
        });

        // Filtrer par période si spécifié
        if ($request->has('date_debut') && $request->has('date_fin')) {
            $query->byPeriod($request->date_debut, $request->date_fin);
        }

        $query->orderBy('date_passage', 'desc');

        $passages = $query->paginate(20);

        // Charger les coiffeurs
        $passages->getCollection()->transform(function ($passage) {
            $passage->prestations->each(function ($prestation) {
                if ($prestation->pivot->coiffeur_id) {
                    $prestation->coiffeur = User::find($prestation->pivot->coiffeur_id);
                }
            });
            return $passage;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'coiffeur' => [
                    'id' => $coiffeur->id,
                    'nom_complet' => $coiffeur->nom_complet,
                ],
                'passages' => $passages,
            ],
        ]);
    }

    /**
     * Vérifier si le prochain passage est gratuit pour un client.
     */
    public function checkFidelite(int $clientId): JsonResponse
    {
        $client = Client::findOrFail($clientId);
        
        $prochainNumero = $client->nombre_passages + 1;
        $passageGratuit = config('app.fidelite_passages_gratuit', 10);
        $estGratuit = $prochainNumero % $passageGratuit === 0;

        return response()->json([
            'success' => true,
            'data' => [
                'client_id' => $client->id,
                'nom_complet' => $client->nom_complet,
                'nombre_passages_actuel' => $client->nombre_passages,
                'prochain_numero' => $prochainNumero,
                'est_gratuit' => $estGratuit,
                'passages_restants' => $estGratuit ? 0 : ($passageGratuit - ($prochainNumero % $passageGratuit)),
            ],
        ]);
    }
}