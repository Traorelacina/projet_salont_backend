<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Prestation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Exception;

class PrestationController extends Controller
{
    /**
     * Liste toutes les prestations.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Prestation::query();

        // Filtrer par statut actif
        if ($request->has('actif')) {
            $query->where('actif', $request->boolean('actif'));
        }

        // Filtrer par type de prestation ou spécialité
        if ($request->has('specialite')) {
            $query->where('specialite', $request->specialite);
        }

        // Charger les coiffeurs associés
        if ($request->boolean('with_coiffeurs', false)) {
            $query->with('coiffeurs:id,nom,prenom,telephone,specialite,role');
        }

        // Tri personnalisé
        if ($request->get('ordered', false)) {
            $query->ordered();
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $prestations = $query->get();

        return response()->json([
            'success' => true,
            'data' => $prestations,
        ]);
    }

    /**
     * Créer une nouvelle prestation.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'libelle' => 'required|string|max:100|unique:prestations,libelle',
                'prix' => 'required|numeric|min:0',
                'description' => 'nullable|string',
                'actif' => 'boolean',
                'ordre' => 'integer|min:0',
                'device_id' => 'nullable|string',
                'duree_estimee' => 'nullable|integer|min:1', // en minutes
                'specialite' => 'nullable|string|in:coiffure,barbe,soin,esthetique',
                'coiffeur_ids' => 'nullable|array',
                'coiffeur_ids.*' => 'exists:users,id',
            ], [
                'libelle.unique' => 'Ce nom de prestation existe déjà. Veuillez choisir un autre nom.',
                'libelle.required' => 'Le nom de la prestation est obligatoire.',
                'prix.required' => 'Le prix est obligatoire.',
                'prix.min' => 'Le prix doit être positif.',
                'duree_estimee.min' => 'La durée estimée doit être positive.',
                'specialite.in' => 'La spécialité doit être: coiffure, barbe, soin, esthetique.',
                'coiffeur_ids.*.exists' => 'Un ou plusieurs coiffeurs sélectionnés n\'existent pas.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $prestation = Prestation::create([
                'libelle' => $request->libelle,
                'prix' => $request->prix,
                'description' => $request->description,
                'actif' => $request->get('actif', true),
                'ordre' => $request->get('ordre', 0),
                'duree_estimee' => $request->duree_estimee,
                'specialite' => $request->specialite,
                'device_id' => $request->device_id,
                'synced_at' => now(),
            ]);

            // Associer les coiffeurs à la prestation
            if ($request->has('coiffeur_ids') && is_array($request->coiffeur_ids)) {
                $prestation->coiffeurs()->sync($request->coiffeur_ids);
            }

            // Charger les coiffeurs pour la réponse
            $prestation->load('coiffeurs:id,nom,prenom,telephone,specialite,role');

            return response()->json([
                'success' => true,
                'message' => 'Prestation créée avec succès',
                'data' => $prestation,
            ], 201);
        } catch (Exception $e) {
            Log::error('Erreur création prestation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la prestation: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Afficher une prestation spécifique.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $prestation = Prestation::with([
                'coiffeurs:id,nom,prenom,telephone,specialite,role,actif',
                'passages' => function ($query) {
                    $query->latest()->limit(10);
                }
            ])->findOrFail($id);

            // Statistiques par coiffeur
            $statistiquesCoiffeurs = $prestation->passages()
                ->selectRaw('coiffeur_id, COUNT(*) as nombre_realisations, SUM(prix_applique) as revenu_total')
                ->whereNotNull('coiffeur_id')
                ->groupBy('coiffeur_id')
                ->with('coiffeur:id,nom,prenom')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'prestation' => $prestation,
                    'statistiques' => [
                        'nombre_utilisations' => $prestation->nombre_utilisations,
                        'revenu_total' => $prestation->revenu_total,
                        'coiffeurs' => $statistiquesCoiffeurs,
                    ],
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Erreur affichage prestation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Prestation non trouvée',
            ], 404);
        }
    }

    /**
     * Mettre à jour une prestation.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $prestation = Prestation::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'libelle' => 'sometimes|required|string|max:100|unique:prestations,libelle,' . $id,
                'prix' => 'sometimes|required|numeric|min:0',
                'description' => 'nullable|string',
                'actif' => 'boolean',
                'ordre' => 'integer|min:0',
                'device_id' => 'nullable|string',
                'duree_estimee' => 'nullable|integer|min:1',
                'specialite' => 'nullable|string|in:coiffure,barbe,soin,esthetique',
                'coiffeur_ids' => 'nullable|array',
                'coiffeur_ids.*' => 'exists:users,id',
            ], [
                'libelle.unique' => 'Ce nom de prestation existe déjà. Veuillez choisir un autre nom.',
                'libelle.required' => 'Le nom de la prestation est obligatoire.',
                'prix.required' => 'Le prix est obligatoire.',
                'prix.min' => 'Le prix doit être positif.',
                'duree_estimee.min' => 'La durée estimée doit être positive.',
                'specialite.in' => 'La spécialité doit être: coiffure, barbe, soin, esthetique.',
                'coiffeur_ids.*.exists' => 'Un ou plusieurs coiffeurs sélectionnés n\'existent pas.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Mettre à jour les attributs de base
            $prestation->update($request->only([
                'libelle', 'prix', 'description', 'actif', 'ordre', 
                'duree_estimee', 'specialite', 'device_id'
            ]));
            
            $prestation->update(['synced_at' => now()]);

            // Mettre à jour les coiffeurs associés
            if ($request->has('coiffeur_ids')) {
                $prestation->coiffeurs()->sync($request->coiffeur_ids ?? []);
            }

            // Charger les coiffeurs pour la réponse
            $prestation->load('coiffeurs:id,nom,prenom,telephone,specialite,role');

            return response()->json([
                'success' => true,
                'message' => 'Prestation mise à jour avec succès',
                'data' => $prestation,
            ]);
        } catch (Exception $e) {
            Log::error('Erreur mise à jour prestation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Supprimer une prestation.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $prestation = Prestation::findOrFail($id);
            
            // Détacher tous les coiffeurs avant suppression
            $prestation->coiffeurs()->detach();
            
            $prestation->delete();

            return response()->json([
                'success' => true,
                'message' => 'Prestation supprimée avec succès',
            ]);
        } catch (Exception $e) {
            Log::error('Erreur suppression prestation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Activer/Désactiver une prestation.
     */
    public function toggleActif(int $id): JsonResponse
    {
        try {
            $prestation = Prestation::findOrFail($id);
            $prestation->update(['actif' => !$prestation->actif]);

            return response()->json([
                'success' => true,
                'message' => $prestation->actif ? 'Prestation activée' : 'Prestation désactivée',
                'data' => $prestation,
            ]);
        } catch (Exception $e) {
            Log::error('Erreur toggle prestation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du changement de statut: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtenir la liste des coiffeurs disponibles pour une prestation.
     */
    public function getCoiffeurs(int $id): JsonResponse
    {
        try {
            $prestation = Prestation::with('coiffeurs:id,nom,prenom,telephone,specialite,role,actif')->findOrFail($id);
            
            // Coiffeurs déjà associés
            $coiffeursAssocies = $prestation->coiffeurs;
            
            // Coiffeurs disponibles (non associés et actifs)
            $coiffeursDisponibles = User::where('role', 'coiffeur')
                ->where('actif', true)
                ->whereNotIn('id', $coiffeursAssocies->pluck('id'))
                ->select('id', 'nom', 'prenom', 'telephone', 'specialite', 'role')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'coiffeurs_associes' => $coiffeursAssocies,
                    'coiffeurs_disponibles' => $coiffeursDisponibles,
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Erreur récupération coiffeurs: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des coiffeurs: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Associer un coiffeur à une prestation.
     */
    public function attachCoiffeur(Request $request, int $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'coiffeur_id' => 'required|exists:users,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $prestation = Prestation::findOrFail($id);
            $coiffeur = User::findOrFail($request->coiffeur_id);

            // Vérifier que l'utilisateur est bien un coiffeur
            if ($coiffeur->role !== 'coiffeur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cet utilisateur n\'est pas un coiffeur',
                ], 400);
            }

            $prestation->coiffeurs()->syncWithoutDetaching([$request->coiffeur_id]);

            return response()->json([
                'success' => true,
                'message' => 'Coiffeur associé avec succès',
                'data' => $coiffeur,
            ]);
        } catch (Exception $e) {
            Log::error('Erreur association coiffeur: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'association: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Détacher un coiffeur d'une prestation.
     */
    public function detachCoiffeur(int $prestationId, int $coiffeurId): JsonResponse
    {
        try {
            $prestation = Prestation::findOrFail($prestationId);
            $prestation->coiffeurs()->detach($coiffeurId);

            return response()->json([
                'success' => true,
                'message' => 'Coiffeur détaché avec succès',
            ]);
        } catch (Exception $e) {
            Log::error('Erreur détachement coiffeur: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du détachement: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtenir les prestations par coiffeur.
     */
    public function byCoiffeur(int $coiffeurId): JsonResponse
    {
        try {
            $coiffeur = User::findOrFail($coiffeurId);
            
            if ($coiffeur->role !== 'coiffeur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cet utilisateur n\'est pas un coiffeur',
                ], 400);
            }

            $prestations = $coiffeur->prestations()
                ->where('actif', true)
                ->orderBy('libelle')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'coiffeur' => $coiffeur,
                    'prestations' => $prestations,
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Erreur prestations par coiffeur: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtenir les prestations les plus populaires.
     */
    public function populaires(Request $request): JsonResponse
    {
        try {
            $limit = $request->get('limit', 10);
            
            $prestations = Prestation::withCount('passages')
                ->orderBy('passages_count', 'desc')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $prestations,
            ]);
        } catch (Exception $e) {
            Log::error('Erreur prestations populaires: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des prestations: ' . $e->getMessage(),
            ], 500);
        }
    }
}