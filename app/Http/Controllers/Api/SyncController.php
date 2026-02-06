<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Passage;
use App\Models\Paiement;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class SyncController extends Controller
{
    /**
     * Synchroniser les données hors ligne avec le serveur.
     * Gère la synchronisation par lots de clients, passages et paiements.
     */
    public function sync(Request $request): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            $validator = Validator::make($request->all(), [
                'sync_data' => 'required|array',
                'sync_data.*.entity' => 'required|in:clients,passages,paiements',
                'sync_data.*.action' => 'required|in:create,update,delete',
                'sync_data.*.data' => 'required|array',
                'sync_data.*.temp_id' => 'sometimes|string',
                'sync_data.*.local_id' => 'sometimes|integer',
            ]);

            if ($validator->fails()) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $syncData = $request->input('sync_data');
            $results = [
                'success' => [],
                'failed' => [],
            ];

            // Trier les données par ordre de dépendance
            // Clients d'abord, puis passages, puis paiements
            usort($syncData, function($a, $b) {
                $order = ['clients' => 1, 'passages' => 2, 'paiements' => 3];
                return ($order[$a['entity']] ?? 999) - ($order[$b['entity']] ?? 999);
            });

            foreach ($syncData as $index => $item) {
                try {
                    $result = $this->syncItem($item);
                    $results['success'][] = [
                        'index' => $index,
                        'entity' => $item['entity'],
                        'action' => $item['action'],
                        'temp_id' => $item['temp_id'] ?? null,
                        'result' => $result,
                    ];
                } catch (Exception $e) {
                    Log::error('Erreur synchronisation item', [
                        'item' => $item,
                        'error' => $e->getMessage(),
                    ]);
                    
                    $results['failed'][] = [
                        'index' => $index,
                        'entity' => $item['entity'],
                        'action' => $item['action'],
                        'temp_id' => $item['temp_id'] ?? null,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => sprintf(
                    'Synchronisation terminée: %d réussie(s), %d échouée(s)',
                    count($results['success']),
                    count($results['failed'])
                ),
                'data' => $results,
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Erreur synchronisation globale: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la synchronisation: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Synchroniser un élément individuel.
     */
    private function syncItem(array $item): array
    {
        $entity = $item['entity'];
        $action = $item['action'];
        $data = $item['data'];

        switch ($entity) {
            case 'clients':
                return $this->syncClient($action, $data, $item);
            case 'passages':
                return $this->syncPassage($action, $data, $item);
            case 'paiements':
                return $this->syncPaiement($action, $data, $item);
            default:
                throw new Exception("Type d'entité non supporté: {$entity}");
        }
    }

    /**
     * Synchroniser un client.
     */
    private function syncClient(string $action, array $data, array $item): array
    {
        if ($action === 'create') {
            // Vérifier si un client avec ce téléphone existe déjà
            $existingClient = null;
            if (!empty($data['telephone'])) {
                $existingClient = Client::where('telephone', $data['telephone'])->first();
            }

            if ($existingClient) {
                // Client existe déjà, retourner ses informations
                return [
                    'action' => 'found_existing',
                    'server_id' => $existingClient->id,
                    'client' => $existingClient,
                    'message' => 'Client existant trouvé avec ce téléphone',
                ];
            }

            // Créer le nouveau client
            $validator = Validator::make($data, [
                'nom' => 'required|string|max:100',
                'prenom' => 'required|string|max:100',
                'telephone' => 'nullable|string|max:20',
            ]);

            if ($validator->fails()) {
                throw new Exception('Données client invalides: ' . json_encode($validator->errors()));
            }

            // Générer un code client unique
            $year = date('y');
            $maxNumber = DB::table('clients')
                ->select(DB::raw('COALESCE(MAX(CAST(SUBSTRING(code_client, 2, 3) AS UNSIGNED)), 0) as max_num'))
                ->whereNotNull('code_client')
                ->where('code_client', 'REGEXP', '^C[0-9]{3}-[0-9]{2}$')
                ->value('max_num');
            
            $nextNumber = $maxNumber + 1;
            $codeClient = sprintf('C%03d-%s', $nextNumber, $year);

            $client = Client::create([
                'nom' => $data['nom'],
                'prenom' => $data['prenom'],
                'telephone' => $data['telephone'] ?? null,
                'code_client' => $codeClient,
                'nombre_passages' => 0,
                'synced_at' => now(),
            ]);

            return [
                'action' => 'created',
                'server_id' => $client->id,
                'client' => $client,
            ];

        } elseif ($action === 'update') {
            $serverId = $item['server_id'] ?? null;
            
            if (!$serverId) {
                throw new Exception('ID serveur manquant pour la mise à jour');
            }

            $client = Client::findOrFail($serverId);
            
            $client->update([
                'nom' => $data['nom'] ?? $client->nom,
                'prenom' => $data['prenom'] ?? $client->prenom,
                'telephone' => $data['telephone'] ?? $client->telephone,
                'synced_at' => now(),
            ]);

            return [
                'action' => 'updated',
                'server_id' => $client->id,
                'client' => $client,
            ];
        }

        throw new Exception("Action non supportée pour les clients: {$action}");
    }

    /**
     * Synchroniser un passage.
     * ✅ AMÉLIORATION : Accepte les deux formats de prestations (id et prestation_id)
     */
    private function syncPassage(string $action, array $data, array $item): array
    {
        if ($action === 'create') {
            // ✅ NOUVEAU : Validation flexible pour accepter 'id' OU 'prestation_id'
            $validator = Validator::make($data, [
                'client_id' => 'required|exists:clients,id',
                'date_passage' => 'required|date',
                'est_gratuit' => 'required|boolean',
                'montant_total' => 'required|numeric|min:0',
                'prestations' => 'required|array|min:1',
                'prestations.*.quantite' => 'required|integer|min:1',
                'prestations.*.prix_unitaire' => 'required|numeric|min:0',
                'prestations.*.coiffeur_id' => 'nullable|exists:users,id',
            ], [
                'prestations.required' => 'Au moins une prestation est requise',
                'prestations.*.quantite.required' => 'La quantité est requise pour chaque prestation',
                'prestations.*.prix_unitaire.required' => 'Le prix unitaire est requis pour chaque prestation',
            ]);

            if ($validator->fails()) {
                throw new Exception('Données passage invalides: ' . json_encode($validator->errors()));
            }

            // ✅ NOUVEAU : Valider que chaque prestation a soit 'id' soit 'prestation_id'
            foreach ($data['prestations'] as $index => $prestationData) {
                if (!isset($prestationData['id']) && !isset($prestationData['prestation_id'])) {
                    throw new Exception("La prestation à l'index {$index} doit avoir un champ 'id' ou 'prestation_id'");
                }
                
                // Vérifier que l'ID existe
                $prestationId = $prestationData['id'] ?? $prestationData['prestation_id'];
                if (!\App\Models\Prestation::where('id', $prestationId)->exists()) {
                    throw new Exception("La prestation {$prestationId} n'existe pas");
                }
            }

            // Créer le passage
            $passage = Passage::create([
                'client_id' => $data['client_id'],
                'date_passage' => $data['date_passage'],
                'est_gratuit' => $data['est_gratuit'],
                'montant_total' => $data['montant_total'],
            ]);

            // ✅ AMÉLIORATION : Attacher les prestations avec support des deux formats
            foreach ($data['prestations'] as $prestationData) {
                // Utiliser 'id' en priorité, sinon 'prestation_id'
                $prestationId = $prestationData['id'] ?? $prestationData['prestation_id'];
                
                $passage->prestations()->attach(
                    $prestationId,
                    [
                        'quantite' => $prestationData['quantite'],
                        'prix_unitaire' => $prestationData['prix_unitaire'],
                        'coiffeur_id' => $prestationData['coiffeur_id'] ?? null,
                    ]
                );
            }

            // Mettre à jour le nombre de passages du client
            $client = Client::find($data['client_id']);
            if ($client) {
                $client->increment('nombre_passages');
            }

            // Charger les relations
            $passage->load(['client', 'prestations', 'paiement']);

            return [
                'action' => 'created',
                'server_id' => $passage->id,
                'passage' => $passage,
            ];

        } elseif ($action === 'update') {
            $serverId = $item['server_id'] ?? null;
            
            if (!$serverId) {
                throw new Exception('ID serveur manquant pour la mise à jour');
            }

            $passage = Passage::findOrFail($serverId);
            
            $passage->update([
                'date_passage' => $data['date_passage'] ?? $passage->date_passage,
                'est_gratuit' => $data['est_gratuit'] ?? $passage->est_gratuit,
                'montant_total' => $data['montant_total'] ?? $passage->montant_total,
            ]);

            // Mettre à jour les prestations si fournies
            if (isset($data['prestations'])) {
                $passage->prestations()->detach();
                
                foreach ($data['prestations'] as $prestationData) {
                    // ✅ Support des deux formats
                    $prestationId = $prestationData['id'] ?? $prestationData['prestation_id'];
                    
                    $passage->prestations()->attach(
                        $prestationId,
                        [
                            'quantite' => $prestationData['quantite'],
                            'prix_unitaire' => $prestationData['prix_unitaire'],
                            'coiffeur_id' => $prestationData['coiffeur_id'] ?? null,
                        ]
                    );
                }
            }

            $passage->load(['client', 'prestations', 'paiement']);

            return [
                'action' => 'updated',
                'server_id' => $passage->id,
                'passage' => $passage,
            ];

        } elseif ($action === 'delete') {
            $serverId = $item['server_id'] ?? null;
            
            if (!$serverId) {
                throw new Exception('ID serveur manquant pour la suppression');
            }

            $passage = Passage::findOrFail($serverId);
            $clientId = $passage->client_id;
            
            $passage->delete();

            // Mettre à jour le nombre de passages du client
            $client = Client::find($clientId);
            if ($client && $client->nombre_passages > 0) {
                $client->decrement('nombre_passages');
            }

            return [
                'action' => 'deleted',
                'server_id' => $serverId,
            ];
        }

        throw new Exception("Action non supportée pour les passages: {$action}");
    }

    /**
     * Synchroniser un paiement.
     */
    private function syncPaiement(string $action, array $data, array $item): array
    {
        if ($action === 'create') {
            $validator = Validator::make($data, [
                'passage_id' => 'required|exists:passages,id',
                'montant' => 'required|numeric|min:0',
                'mode_paiement' => 'required|in:espece,carte,mobile',
                'numero_telephone' => 'nullable|string',
                'reference_transaction' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                throw new Exception('Données paiement invalides: ' . json_encode($validator->errors()));
            }

            $paiement = Paiement::create([
                'passage_id' => $data['passage_id'],
                'montant' => $data['montant'],
                'mode_paiement' => $data['mode_paiement'],
                'numero_telephone' => $data['numero_telephone'] ?? null,
                'reference_transaction' => $data['reference_transaction'] ?? null,
                'date_paiement' => now(),
            ]);

            return [
                'action' => 'created',
                'server_id' => $paiement->id,
                'paiement' => $paiement,
            ];
        }

        throw new Exception("Action non supportée pour les paiements: {$action}");
    }

    /**
     * Obtenir les statistiques de synchronisation.
     */
    public function syncStats(Request $request): JsonResponse
    {
        try {
            $stats = [
                'total_clients' => Client::count(),
                'clients_synced_today' => Client::whereDate('synced_at', today())->count(),
                'total_passages' => Passage::count(),
                'passages_today' => Passage::whereDate('date_passage', today())->count(),
                'last_sync' => Client::max('synced_at'),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (Exception $e) {
            Log::error('Erreur statistiques sync: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques',
            ], 500);
        }
    }
}