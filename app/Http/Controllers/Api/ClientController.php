<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class ClientController extends Controller
{
    /**
     * Liste tous les clients.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Client::query();

        // Recherche par nom, téléphone ou code client
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('nom', 'LIKE', "%{$search}%")
                  ->orWhere('prenom', 'LIKE', "%{$search}%")
                  ->orWhere('telephone', 'LIKE', "%{$search}%")
                  ->orWhere('code_client', 'LIKE', "%{$search}%")
                  ->orWhere(DB::raw("CONCAT(prenom, ' ', nom)"), 'LIKE', "%{$search}%");
            });
        }

        // Tri
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->get('per_page', 15);
        $clients = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $clients,
        ]);
    }

    /**
     * Générer un code client unique au format C001-26
     */
    public function generateCode(): JsonResponse
    {
        try {
            // Récupérer l'année en cours (2 derniers chiffres)
            $year = date('y'); // Par exemple: 26 pour 2026
            
            // Récupérer le dernier code client de l'année en cours
            $lastClient = Client::where('code_client', 'LIKE', "C%-{$year}")
                ->orderBy('code_client', 'desc')
                ->first();
            
            if ($lastClient && preg_match('/C(\d{3})-' . $year . '/', $lastClient->code_client, $matches)) {
                // Incrémenter le numéro
                $nextNumber = intval($matches[1]) + 1;
            } else {
                // Premier client de l'année
                $nextNumber = 1;
            }
            
            // Formater le code: C + numéro sur 3 chiffres + - + année
            $codeClient = sprintf('C%03d-%s', $nextNumber, $year);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'code_client' => $codeClient,
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Erreur génération code client: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération du code client: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Créer un nouveau client.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Validation avec téléphone optionnel
            $validator = Validator::make($request->all(), [
                'nom' => 'required|string|max:100',
                'prenom' => 'required|string|max:100',
                'telephone' => [
                    'nullable',
                    'string',
                    'max:20',
                    // Unique seulement si le téléphone est fourni et non vide
                    function ($attribute, $value, $fail) {
                        if (!empty($value)) {
                            $exists = Client::where('telephone', $value)->exists();
                            if ($exists) {
                                $fail('Ce numéro de téléphone est déjà utilisé.');
                            }
                        }
                    },
                ],
                'code_client' => 'nullable|string|max:20|unique:clients,code_client',
                'device_id' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Générer le code client s'il n'est pas fourni
            $codeClient = $request->code_client;
            if (!$codeClient) {
                $year = date('y');
                $lastClient = Client::where('code_client', 'LIKE', "C%-{$year}")
                    ->orderBy('code_client', 'desc')
                    ->first();
                
                if ($lastClient && preg_match('/C(\d{3})-' . $year . '/', $lastClient->code_client, $matches)) {
                    $nextNumber = intval($matches[1]) + 1;
                } else {
                    $nextNumber = 1;
                }
                
                $codeClient = sprintf('C%03d-%s', $nextNumber, $year);
            }

            // Vérifier que le code a bien été généré
            if (empty($codeClient)) {
                Log::error('Code client vide après génération');
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de générer le code client. Veuillez réessayer.',
                ], 500);
            }

            // Nettoyer le téléphone (null si vide)
            $telephone = !empty($request->telephone) ? $request->telephone : null;

            $client = Client::create([
                'nom' => $request->nom,
                'prenom' => $request->prenom,
                'telephone' => $telephone,
                'code_client' => $codeClient,
                'nombre_passages' => 0,
                'device_id' => $request->device_id,
                'synced_at' => now(),
            ]);

            // Vérifier que le client a bien été créé avec un code
            if (!$client || !$client->code_client) {
                Log::error('Client créé sans code_client', ['client' => $client]);
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur lors de la création du client. Le code client n\'a pas été enregistré.',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Client créé avec succès',
                'data' => $client,
            ], 201);
            
        } catch (Exception $e) {
            Log::error('Erreur création client: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du client: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Afficher un client spécifique.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $client = Client::with(['passages.prestations', 'passages.paiement'])
                ->findOrFail($id);

            // Calculer le prochain passage gratuit (10ème passage)
            $prochainPassageGratuit = 10 - ($client->nombre_passages % 10);
            if ($prochainPassageGratuit == 10) {
                $prochainPassageGratuit = 0; // Le prochain est gratuit
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'client' => $client,
                    'statistiques' => [
                        'nombre_passages' => $client->nombre_passages,
                        'chiffre_affaires_total' => $client->chiffre_affaires_total ?? 0,
                        'derniere_visite' => $client->derniere_visite,
                        'prochain_passage_gratuit' => $prochainPassageGratuit,
                    ],
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Erreur affichage client: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Client non trouvé',
            ], 404);
        }
    }

    /**
     * Mettre à jour un client.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $client = Client::findOrFail($id);

            // Validation avec téléphone optionnel
            $validator = Validator::make($request->all(), [
                'nom' => 'sometimes|required|string|max:100',
                'prenom' => 'sometimes|required|string|max:100',
                'telephone' => [
                    'nullable',
                    'string',
                    'max:20',
                    // Unique seulement si le téléphone est fourni et différent de l'actuel
                    function ($attribute, $value, $fail) use ($id) {
                        if (!empty($value)) {
                            $exists = Client::where('telephone', $value)
                                ->where('id', '!=', $id)
                                ->exists();
                            if ($exists) {
                                $fail('Ce numéro de téléphone est déjà utilisé.');
                            }
                        }
                    },
                ],
                'code_client' => 'sometimes|required|string|max:20|unique:clients,code_client,' . $id,
                'device_id' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Nettoyer le téléphone (null si vide)
            $updateData = $request->only(['nom', 'prenom', 'code_client', 'device_id']);
            if ($request->has('telephone')) {
                $updateData['telephone'] = !empty($request->telephone) ? $request->telephone : null;
            }

            $client->update($updateData);
            $client->update(['synced_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => 'Client mis à jour avec succès',
                'data' => $client,
            ]);
        } catch (Exception $e) {
            Log::error('Erreur mise à jour client: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Supprimer un client.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $client = Client::findOrFail($id);
            $client->delete();

            return response()->json([
                'success' => true,
                'message' => 'Client supprimé avec succès',
            ]);
        } catch (Exception $e) {
            Log::error('Erreur suppression client: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Rechercher un client par téléphone.
     */
    public function searchByPhone(string $phone): JsonResponse
    {
        try {
            $clients = Client::where('telephone', 'LIKE', "%{$phone}%")->get();

            return response()->json([
                'success' => true,
                'data' => $clients,
            ]);
        } catch (Exception $e) {
            Log::error('Erreur recherche client: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la recherche: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtenir l'historique d'un client.
     */
    public function historique(int $id): JsonResponse
    {
        try {
            $client = Client::findOrFail($id);
            
            $passages = $client->passages()
                ->with(['prestations', 'paiement'])
                ->orderBy('date_passage', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data' => [
                    'client' => $client,
                    'passages' => $passages,
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Erreur historique client: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'historique: ' . $e->getMessage(),
            ], 500);
        }
    }
}