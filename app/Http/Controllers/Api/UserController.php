<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Prestation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    /**
     * Liste tous les utilisateurs (Admin uniquement).
     */
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->canManageUsers()) {
            return response()->json([
                'success' => false,
                'message' => 'Accès non autorisé',
            ], 403);
        }

        $query = User::query();

        // Filtrer par rôle si spécifié
        if ($request->has('role')) {
            $query->byRole($request->role);
        }

        // Filtrer par spécialité pour les coiffeurs
        if ($request->has('specialite')) {
            $query->where('specialite', $request->specialite);
        }

        // Filtrer par statut actif/inactif
        if ($request->has('actif')) {
            $query->where('actif', $request->boolean('actif'));
        }

        // Charger les relations pour les coiffeurs
        if ($request->boolean('with_prestations', false)) {
            $query->with('prestations:id,libelle,prix');
        }

        $users = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $users->map(function ($user) {
                $userData = [
                    'id' => $user->id,
                    'nom' => $user->nom,
                    'prenom' => $user->prenom,
                    'email' => $user->email,
                    'telephone' => $user->telephone,
                    'role' => $user->role,
                    'role_label' => $user->role_label,
                    'actif' => $user->actif,
                    'nom_complet' => $user->nom_complet,
                    'created_at' => $user->created_at,
                    'needs_account' => $user->needsAccount(),
                ];

                // Ajouter les champs spécifiques aux coiffeurs
                if ($user->isCoiffeur()) {
                    $userData['specialite'] = $user->specialite;
                    $userData['specialite_label'] = $user->specialite_label;
                    $userData['commission'] = $user->commission;
                    
                    // Statistiques rapides pour les coiffeurs
                    if ($user->relationLoaded('prestations')) {
                        $userData['nombre_prestations'] = $user->prestations->count();
                        $userData['prestations'] = $user->prestations->map(function ($prestation) {
                            return [
                                'id' => $prestation->id,
                                'libelle' => $prestation->libelle,
                                'prix' => $prestation->prix,
                            ];
                        });
                    }
                }

                return $userData;
            }),
        ]);
    }

    /**
     * Liste tous les coiffeurs actifs.
     */
    public function coiffeurs(Request $request): JsonResponse
    {
        $query = User::where('role', 'coiffeur')
            ->where('actif', true);

        // Filtrer par spécialité
        if ($request->has('specialite')) {
            $query->where('specialite', $request->specialite);
        }

        // Charger les prestations associées
        if ($request->boolean('with_prestations', false)) {
            $query->with('prestations:id,libelle,prix');
        }

        $coiffeurs = $query->orderBy('prenom')
            ->orderBy('nom')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $coiffeurs->map(function ($coiffeur) {
                $data = [
                    'id' => $coiffeur->id,
                    'nom_complet' => $coiffeur->nom_complet,
                    'nom' => $coiffeur->nom,
                    'prenom' => $coiffeur->prenom,
                    'email' => $coiffeur->email,
                    'telephone' => $coiffeur->telephone,
                    'specialite' => $coiffeur->specialite,
                    'specialite_label' => $coiffeur->specialite_label,
                    'commission' => $coiffeur->commission,
                ];

                if ($coiffeur->relationLoaded('prestations')) {
                    $data['prestations'] = $coiffeur->prestations->map(function ($prestation) {
                        return [
                            'id' => $prestation->id,
                            'libelle' => $prestation->libelle,
                            'prix' => $prestation->prix,
                        ];
                    });
                    $data['nombre_prestations'] = $coiffeur->prestations->count();
                }

                return $data;
            }),
        ]);
    }

    /**
     * Créer un nouvel utilisateur (Admin uniquement).
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->canManageUsers()) {
            return response()->json([
                'success' => false,
                'message' => 'Accès non autorisé',
            ], 403);
        }

        try {
            $role = $request->input('role', 'caissier');
            
            // Règles de base communes à tous les utilisateurs
            $rules = [
                'nom' => 'required|string|max:255',
                'prenom' => 'required|string|max:255',
                'telephone' => 'nullable|string|max:20',
                'role' => ['required', Rule::in(['admin', 'manager', 'caissier', 'coiffeur'])],
                'actif' => 'sometimes|boolean',
            ];

            // Règles conditionnelles pour les coiffeurs
            if ($role === 'coiffeur') {
                $rules['email'] = 'nullable|email|unique:users,email';
                $rules['password'] = 'nullable|string|min:6';
                $rules['specialite'] = 'nullable|string|max:255';
                $rules['commission'] = 'nullable|numeric|min:0|max:100';
            } else {
                // Pour les autres rôles : email et password obligatoires
                $rules['email'] = 'required|email|unique:users,email';
                $rules['password'] = 'required|string|min:6|confirmed';
            }

            $validator = Validator::make($request->all(), $rules, [
                'email.required' => 'L\'email est obligatoire pour ce rôle',
                'email.unique' => 'Cet email est déjà utilisé',
                'password.required' => 'Le mot de passe est obligatoire pour ce rôle',
                'password.min' => 'Le mot de passe doit contenir au moins 6 caractères',
                'password.confirmed' => 'La confirmation du mot de passe ne correspond pas',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Préparer les données
            $userData = [
                'nom' => $request->nom,
                'prenom' => $request->prenom,
                'email' => $request->email,
                'telephone' => $request->telephone,
                'role' => $role,
                'actif' => $request->actif ?? true,
            ];

            // Pour les coiffeurs
            if ($role === 'coiffeur') {
                $userData['specialite'] = $request->specialite;
                $userData['commission'] = $request->commission ?? 30.00; // 30% par défaut
                
                // Hash du mot de passe seulement s'il est fourni
                if ($request->password) {
                    $userData['password'] = Hash::make($request->password);
                } else {
                    $userData['password'] = null; // Coiffeur sans compte
                }
            } else {
                // Pour les autres rôles : password obligatoire
                $userData['password'] = Hash::make($request->password);
            }

            DB::beginTransaction();

            $user = User::create($userData);

            // Associer les prestations au coiffeur si fournies
            if ($role === 'coiffeur' && $request->has('prestation_ids') && is_array($request->prestation_ids)) {
                $prestations = Prestation::whereIn('id', $request->prestation_ids)
                    ->where('actif', true)
                    ->get();
                
                if ($prestations->isNotEmpty()) {
                    $user->prestations()->sync($prestations->pluck('id')->toArray());
                }
            }

            DB::commit();

            // Charger les relations si nécessaire
            if ($role === 'coiffeur') {
                $user->load('prestations:id,libelle,prix');
            }

            return response()->json([
                'success' => true,
                'message' => $role === 'coiffeur' ? 'Coiffeur créé avec succès' : 'Utilisateur créé avec succès',
                'data' => $this->formatUserData($user),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur création utilisateur: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de l\'utilisateur',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Afficher un utilisateur spécifique (Admin uniquement).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageUsers()) {
            return response()->json([
                'success' => false,
                'message' => 'Accès non autorisé',
            ], 403);
        }

        $user = User::with($request->input('with', []))->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatUserData($user, true),
        ]);
    }

    /**
     * Mettre à jour un utilisateur (Admin uniquement).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageUsers()) {
            return response()->json([
                'success' => false,
                'message' => 'Accès non autorisé',
            ], 403);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable',
            ], 404);
        }

        try {
            $role = $request->input('role', $user->role);
            
            // Règles de base
            $rules = [
                'nom' => 'sometimes|string|max:255',
                'prenom' => 'sometimes|string|max:255',
                'telephone' => 'nullable|string|max:20',
                'role' => ['sometimes', Rule::in(['admin', 'manager', 'caissier', 'coiffeur'])],
                'actif' => 'sometimes|boolean',
            ];

            // Règles pour l'email
            if ($role === 'coiffeur') {
                $rules['email'] = 'nullable|email|unique:users,email,' . $user->id;
            } else {
                $rules['email'] = 'sometimes|email|unique:users,email,' . $user->id;
            }

            // Règles pour le mot de passe
            if ($request->has('password')) {
                if ($role === 'coiffeur') {
                    $rules['password'] = 'nullable|string|min:6';
                } else {
                    $rules['password'] = 'sometimes|string|min:6|confirmed';
                }
            }

            // Règles spécifiques aux coiffeurs
            if ($role === 'coiffeur') {
                $rules['specialite'] = 'nullable|string|max:255';
                $rules['commission'] = 'nullable|numeric|min:0|max:100';
            }

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors(),
                ], 422);
            }

            DB::beginTransaction();

            // Préparer les données de mise à jour
            $data = $request->only(['nom', 'prenom', 'telephone', 'role', 'actif']);
            
            // Gérer l'email
            if ($request->has('email')) {
                if ($role === 'coiffeur' && empty($request->email)) {
                    $data['email'] = null;
                } else {
                    $data['email'] = $request->email;
                }
            }

            // Gérer le mot de passe
            if ($request->has('password') && !empty($request->password)) {
                $data['password'] = Hash::make($request->password);
            } elseif ($role === 'coiffeur' && $request->has('password') && empty($request->password)) {
                // Pour un coiffeur, on peut supprimer le mot de passe
                $data['password'] = null;
            }

            // Gérer les champs spécifiques aux coiffeurs
            if ($role === 'coiffeur') {
                $data['specialite'] = $request->specialite ?? $user->specialite;
                $data['commission'] = $request->commission ?? $user->commission;
                
                // Si on change un utilisateur en coiffeur et qu'il n'a pas d'email, le mettre à null
                if ($user->role !== 'coiffeur' && $role === 'coiffeur' && empty($request->email)) {
                    $data['email'] = null;
                }
            }

            // Mettre à jour l'utilisateur
            $user->update($data);

            // Mettre à jour les prestations pour les coiffeurs
            if ($role === 'coiffeur' && $request->has('prestation_ids')) {
                $prestationIds = is_array($request->prestation_ids) ? $request->prestation_ids : [];
                $user->prestations()->sync($prestationIds);
            }

            DB::commit();

            // Recharger les relations
            if ($user->isCoiffeur()) {
                $user->load('prestations:id,libelle,prix');
            }

            return response()->json([
                'success' => true,
                'message' => $user->isCoiffeur() ? 'Coiffeur mis à jour avec succès' : 'Utilisateur mis à jour avec succès',
                'data' => $this->formatUserData($user),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur mise à jour utilisateur: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Supprimer un utilisateur (Admin uniquement).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageUsers()) {
            return response()->json([
                'success' => false,
                'message' => 'Accès non autorisé',
            ], 403);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable',
            ], 404);
        }

        // Empêcher la suppression de son propre compte
        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez pas supprimer votre propre compte',
            ], 400);
        }

        try {
            // Si c'est un coiffeur, détacher ses prestations d'abord
            if ($user->isCoiffeur()) {
                $user->prestations()->detach();
            }

            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur supprimé avec succès',
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur suppression utilisateur: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Activer/Désactiver un utilisateur (Admin uniquement).
     */
    public function toggleActif(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->canManageUsers()) {
            return response()->json([
                'success' => false,
                'message' => 'Accès non autorisé',
            ], 403);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable',
            ], 404);
        }

        // Empêcher la désactivation de son propre compte
        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez pas désactiver votre propre compte',
            ], 400);
        }

        $user->actif = !$user->actif;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => $user->actif 
                ? ($user->isCoiffeur() ? 'Coiffeur activé avec succès' : 'Utilisateur activé avec succès')
                : ($user->isCoiffeur() ? 'Coiffeur désactivé avec succès' : 'Utilisateur désactivé avec succès'),
            'data' => [
                'id' => $user->id,
                'actif' => $user->actif,
            ],
        ]);
    }

    /**
     * Obtenir les statistiques d'un coiffeur.
     */
    public function statistiquesCoiffeur(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->role !== 'coiffeur') {
            return response()->json([
                'success' => false,
                'message' => 'Cet utilisateur n\'est pas un coiffeur',
            ], 400);
        }

        // Récupérer les prestations réalisées par le coiffeur
        $prestations = DB::table('passage_prestation')
            ->where('coiffeur_id', $id)
            ->join('passages', 'passage_prestation.passage_id', '=', 'passages.id')
            ->join('prestations', 'passage_prestation.prestation_id', '=', 'prestations.id');

        // Filtrer par période si spécifié
        if ($request->has('date_debut') && $request->has('date_fin')) {
            $prestations->whereBetween('passages.date_passage', [
                $request->date_debut,
                $request->date_fin
            ]);
        }

        $stats = $prestations->selectRaw('
            COUNT(*) as nombre_prestations,
            SUM(passage_prestation.quantite) as total_quantite,
            SUM(passage_prestation.prix_applique * passage_prestation.quantite) as revenu_total
        ')->first();

        // Prestations par type
        $prestationsParType = DB::table('passage_prestation')
            ->where('coiffeur_id', $id)
            ->join('prestations', 'passage_prestation.prestation_id', '=', 'prestations.id')
            ->selectRaw('
                prestations.id,
                prestations.libelle,
                prestations.prix,
                COUNT(*) as nombre,
                SUM(passage_prestation.quantite) as quantite_totale,
                SUM(passage_prestation.prix_applique * passage_prestation.quantite) as revenu
            ')
            ->groupBy('prestations.id', 'prestations.libelle', 'prestations.prix')
            ->orderBy('nombre', 'desc')
            ->get();

        // Statistiques par mois
        $statsMensuelles = DB::table('passage_prestation')
            ->where('coiffeur_id', $id)
            ->join('passages', 'passage_prestation.passage_id', '=', 'passages.id')
            ->selectRaw('
                DATE_FORMAT(passages.date_passage, "%Y-%m") as mois,
                COUNT(*) as nombre_prestations,
                SUM(passage_prestation.quantite) as quantite_totale,
                SUM(passage_prestation.prix_applique * passage_prestation.quantite) as revenu
            ')
            ->groupBy('mois')
            ->orderBy('mois', 'desc')
            ->limit(6)
            ->get();

        // Calculer la commission estimée
        $commissionEstimee = ($stats->revenu_total ?? 0) * ($user->commission / 100);

        return response()->json([
            'success' => true,
            'data' => [
                'coiffeur' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom_complet,
                    'nom' => $user->nom,
                    'prenom' => $user->prenom,
                    'specialite' => $user->specialite,
                    'specialite_label' => $user->specialite_label,
                    'commission' => $user->commission,
                    'actif' => $user->actif,
                ],
                'statistiques' => [
                    'nombre_prestations' => $stats->nombre_prestations ?? 0,
                    'total_quantite' => $stats->total_quantite ?? 0,
                    'revenu_total' => $stats->revenu_total ?? 0,
                    'commission_estimee' => $commissionEstimee,
                    'prix_moyen' => $stats->total_quantite > 0 
                        ? ($stats->revenu_total / $stats->total_quantite)
                        : 0,
                ],
                'prestations_par_type' => $prestationsParType,
                'statistiques_mensuelles' => $statsMensuelles,
            ],
        ]);
    }

    /**
     * Associer une prestation à un coiffeur.
     */
    public function associerPrestation(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if (!$user->isCoiffeur()) {
            return response()->json([
                'success' => false,
                'message' => 'Cet utilisateur n\'est pas un coiffeur',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'prestation_id' => 'required|exists:prestations,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $prestation = Prestation::findOrFail($request->prestation_id);
            
            // Vérifier que la prestation est active
            if (!$prestation->actif) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cette prestation est inactive',
                ], 400);
            }

            $user->prestations()->syncWithoutDetaching([$prestation->id]);

            return response()->json([
                'success' => true,
                'message' => 'Prestation associée avec succès',
                'data' => [
                    'prestation' => [
                        'id' => $prestation->id,
                        'libelle' => $prestation->libelle,
                        'prix' => $prestation->prix,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur association prestation: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'association',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Détacher une prestation d'un coiffeur.
     */
    public function detacherPrestation(Request $request, int $coiffeurId, int $prestationId): JsonResponse
    {
        $user = User::findOrFail($coiffeurId);

        if (!$user->isCoiffeur()) {
            return response()->json([
                'success' => false,
                'message' => 'Cet utilisateur n\'est pas un coiffeur',
            ], 400);
        }

        try {
            $user->prestations()->detach($prestationId);

            return response()->json([
                'success' => true,
                'message' => 'Prestation détachée avec succès',
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur détachement prestation: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du détachement',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Formater les données utilisateur pour la réponse.
     */
    private function formatUserData(User $user, bool $detailed = false): array
    {
        $data = [
            'id' => $user->id,
            'nom' => $user->nom,
            'prenom' => $user->prenom,
            'email' => $user->email,
            'telephone' => $user->telephone,
            'role' => $user->role,
            'role_label' => $user->role_label,
            'actif' => $user->actif,
            'nom_complet' => $user->nom_complet,
            'needs_account' => $user->needsAccount(),
            'has_account' => $user->hasAccount(),
        ];

        // Ajouter les champs spécifiques aux coiffeurs
        if ($user->isCoiffeur()) {
            $data['specialite'] = $user->specialite;
            $data['specialite_label'] = $user->specialite_label;
            $data['commission'] = $user->commission;

            // Ajouter les prestations si chargées
            if ($user->relationLoaded('prestations')) {
                $data['prestations'] = $user->prestations->map(function ($prestation) {
                    return [
                        'id' => $prestation->id,
                        'libelle' => $prestation->libelle,
                        'prix' => $prestation->prix,
                        'duree_estimee' => $prestation->duree_estimee,
                        'specialite' => $prestation->specialite,
                    ];
                });
                $data['nombre_prestations'] = $user->prestations->count();
            }
        }

        // Ajouter les dates si détaillé
        if ($detailed) {
            $data['created_at'] = $user->created_at;
            $data['updated_at'] = $user->updated_at;
            $data['email_verified_at'] = $user->email_verified_at;
        }

        return $data;
    }
}