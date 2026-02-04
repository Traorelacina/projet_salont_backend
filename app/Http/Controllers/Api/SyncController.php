<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Prestation;
use App\Models\Passage;
use App\Models\Paiement;
use App\Models\SyncLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SyncController extends Controller
{
    /**
     * Synchronisation par lot (batch).
     * Reçoit plusieurs entités à synchroniser en une seule requête.
     */
    public function batch(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|string',
            'data' => 'required|array',
            'data.clients' => 'sometimes|array',
            'data.prestations' => 'sometimes|array',
            'data.passages' => 'sometimes|array',
            'data.paiements' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $deviceId = $request->device_id;
        $data = $request->data;
        $results = [
            'clients' => [],
            'prestations' => [],
            'passages' => [],
            'paiements' => [],
        ];

        try {
            DB::beginTransaction();

            // Synchroniser les clients
            if (isset($data['clients'])) {
                $results['clients'] = $this->syncClients($data['clients'], $deviceId);
            }

            // Synchroniser les prestations
            if (isset($data['prestations'])) {
                $results['prestations'] = $this->syncPrestations($data['prestations'], $deviceId);
            }

            // Synchroniser les passages
            if (isset($data['passages'])) {
                $results['passages'] = $this->syncPassages($data['passages'], $deviceId);
            }

            // Synchroniser les paiements
            if (isset($data['paiements'])) {
                $results['paiements'] = $this->syncPaiements($data['paiements'], $deviceId);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Synchronisation effectuée avec succès',
                'data' => $results,
                'timestamp' => now()->toIso8601String(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la synchronisation',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Synchroniser les clients.
     */
    protected function syncClients(array $clients, string $deviceId): array
    {
        $results = [];

        foreach ($clients as $clientData) {
            try {
                $localId = $clientData['local_id'] ?? null;
                $action = $clientData['action'] ?? 'create';

                if ($action === 'create') {
                    // Vérifier si le client existe déjà par téléphone
                    $existingClient = Client::where('telephone', $clientData['telephone'])->first();
                    
                    if ($existingClient) {
                        // Conflit : client existe déjà
                        $this->logSync($deviceId, 'client', $existingClient->id, 'create', $clientData, null, 'conflit', 'Client existe déjà');
                        
                        $results[] = [
                            'local_id' => $localId,
                            'server_id' => $existingClient->id,
                            'status' => 'conflit',
                            'message' => 'Client existe déjà',
                            'data' => $existingClient,
                        ];
                    } else {
                        // Créer le nouveau client
                        $client = Client::create([
                            'nom' => $clientData['nom'],
                            'prenom' => $clientData['prenom'],
                            'telephone' => $clientData['telephone'],
                            'nombre_passages' => $clientData['nombre_passages'] ?? 0,
                            'device_id' => $deviceId,
                            'synced_at' => now(),
                        ]);

                        $this->logSync($deviceId, 'client', $client->id, 'create', null, $clientData, 'succes');

                        $results[] = [
                            'local_id' => $localId,
                            'server_id' => $client->id,
                            'status' => 'succes',
                            'data' => $client,
                        ];
                    }
                } elseif ($action === 'update') {
                    $serverId = $clientData['server_id'];
                    $client = Client::find($serverId);

                    if ($client) {
                        $oldData = $client->toArray();
                        $client->update([
                            'nom' => $clientData['nom'],
                            'prenom' => $clientData['prenom'],
                            'telephone' => $clientData['telephone'],
                            'device_id' => $deviceId,
                            'synced_at' => now(),
                        ]);

                        $this->logSync($deviceId, 'client', $client->id, 'update', $oldData, $clientData, 'succes');

                        $results[] = [
                            'local_id' => $localId,
                            'server_id' => $client->id,
                            'status' => 'succes',
                            'data' => $client,
                        ];
                    } else {
                        $results[] = [
                            'local_id' => $localId,
                            'server_id' => $serverId,
                            'status' => 'echec',
                            'message' => 'Client introuvable',
                        ];
                    }
                }
            } catch (\Exception $e) {
                $results[] = [
                    'local_id' => $localId ?? null,
                    'status' => 'echec',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Synchroniser les prestations.
     */
    protected function syncPrestations(array $prestations, string $deviceId): array
    {
        $results = [];

        foreach ($prestations as $prestationData) {
            try {
                $localId = $prestationData['local_id'] ?? null;
                $action = $prestationData['action'] ?? 'create';

                if ($action === 'create') {
                    $prestation = Prestation::create([
                        'libelle' => $prestationData['libelle'],
                        'prix' => $prestationData['prix'],
                        'description' => $prestationData['description'] ?? null,
                        'actif' => $prestationData['actif'] ?? true,
                        'ordre' => $prestationData['ordre'] ?? 0,
                        'device_id' => $deviceId,
                        'synced_at' => now(),
                    ]);

                    $this->logSync($deviceId, 'prestation', $prestation->id, 'create', null, $prestationData, 'succes');

                    $results[] = [
                        'local_id' => $localId,
                        'server_id' => $prestation->id,
                        'status' => 'succes',
                        'data' => $prestation,
                    ];
                } elseif ($action === 'update') {
                    $serverId = $prestationData['server_id'];
                    $prestation = Prestation::find($serverId);

                    if ($prestation) {
                        $oldData = $prestation->toArray();
                        $prestation->update([
                            'libelle' => $prestationData['libelle'],
                            'prix' => $prestationData['prix'],
                            'description' => $prestationData['description'] ?? null,
                            'actif' => $prestationData['actif'] ?? true,
                            'ordre' => $prestationData['ordre'] ?? 0,
                            'device_id' => $deviceId,
                            'synced_at' => now(),
                        ]);

                        $this->logSync($deviceId, 'prestation', $prestation->id, 'update', $oldData, $prestationData, 'succes');

                        $results[] = [
                            'local_id' => $localId,
                            'server_id' => $prestation->id,
                            'status' => 'succes',
                            'data' => $prestation,
                        ];
                    }
                }
            } catch (\Exception $e) {
                $results[] = [
                    'local_id' => $localId ?? null,
                    'status' => 'echec',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Synchroniser les passages.
     */
    protected function syncPassages(array $passages, string $deviceId): array
    {
        $results = [];

        foreach ($passages as $passageData) {
            try {
                $localId = $passageData['local_id'] ?? null;
                $action = $passageData['action'] ?? 'create';

                if ($action === 'create') {
                    // Créer le passage
                    $passage = Passage::create([
                        'client_id' => $passageData['client_id'],
                        'numero_passage' => $passageData['numero_passage'],
                        'est_gratuit' => $passageData['est_gratuit'] ?? false,
                        'notes' => $passageData['notes'] ?? null,
                        'date_passage' => $passageData['date_passage'] ?? now(),
                        'device_id' => $deviceId,
                        'synced_at' => now(),
                    ]);

                    // Attacher les prestations
                    if (isset($passageData['prestations'])) {
                        foreach ($passageData['prestations'] as $prest) {
                            $passage->prestations()->attach($prest['id'], [
                                'prix_applique' => $prest['prix_applique'],
                                'quantite' => $prest['quantite'] ?? 1,
                            ]);
                        }
                    }

                    $this->logSync($deviceId, 'passage', $passage->id, 'create', null, $passageData, 'succes');

                    $results[] = [
                        'local_id' => $localId,
                        'server_id' => $passage->id,
                        'status' => 'succes',
                        'data' => $passage->load('prestations'),
                    ];
                }
            } catch (\Exception $e) {
                $results[] = [
                    'local_id' => $localId ?? null,
                    'status' => 'echec',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Synchroniser les paiements.
     */
    protected function syncPaiements(array $paiements, string $deviceId): array
    {
        $results = [];

        foreach ($paiements as $paiementData) {
            try {
                $localId = $paiementData['local_id'] ?? null;
                $action = $paiementData['action'] ?? 'create';

                if ($action === 'create') {
                    $paiement = Paiement::create([
                        'passage_id' => $paiementData['passage_id'],
                        'montant_total' => $paiementData['montant_total'],
                        'montant_paye' => $paiementData['montant_paye'],
                        'mode_paiement' => $paiementData['mode_paiement'],
                        'statut' => $paiementData['statut'] ?? 'valide',
                        'notes' => $paiementData['notes'] ?? null,
                        'date_paiement' => $paiementData['date_paiement'] ?? now(),
                        'device_id' => $deviceId,
                        'synced_at' => now(),
                    ]);

                    $this->logSync($deviceId, 'paiement', $paiement->id, 'create', null, $paiementData, 'succes');

                    $results[] = [
                        'local_id' => $localId,
                        'server_id' => $paiement->id,
                        'status' => 'succes',
                        'data' => $paiement,
                    ];
                }
            } catch (\Exception $e) {
                $results[] = [
                    'local_id' => $localId ?? null,
                    'status' => 'echec',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Logger une opération de synchronisation.
     */
    protected function logSync($deviceId, $entityType, $entityId, $action, $dataBefore, $dataAfter, $statut, $messageErreur = null)
    {
        SyncLog::create([
            'device_id' => $deviceId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'data_before' => $dataBefore,
            'data_after' => $dataAfter,
            'statut' => $statut,
            'message_erreur' => $messageErreur,
            'date_sync' => now(),
        ]);
    }

    /**
     * Obtenir le statut de synchronisation.
     */
    public function status(Request $request): JsonResponse
    {
        $deviceId = $request->device_id;

        if (!$deviceId) {
            return response()->json([
                'success' => false,
                'message' => 'device_id requis',
            ], 422);
        }

        $logs = SyncLog::byDevice($deviceId)
            ->orderBy('date_sync', 'desc')
            ->limit(50)
            ->get();

        $stats = [
            'total' => $logs->count(),
            'succes' => $logs->where('statut', 'succes')->count(),
            'echecs' => $logs->where('statut', 'echec')->count(),
            'conflits' => $logs->where('statut', 'conflit')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'logs' => $logs,
                'statistiques' => $stats,
            ],
        ]);
    }

    /**
     * Obtenir les mises à jour depuis le serveur.
     */
    public function pull(Request $request): JsonResponse
    {
        $timestamp = $request->timestamp;

        if (!$timestamp) {
            return response()->json([
                'success' => false,
                'message' => 'timestamp requis',
            ], 422);
        }

        $data = [
            'clients' => Client::where('synced_at', '>', $timestamp)->get(),
            'prestations' => Prestation::where('synced_at', '>', $timestamp)->get(),
            'passages' => Passage::with('prestations')->where('synced_at', '>', $timestamp)->get(),
            'paiements' => Paiement::where('synced_at', '>', $timestamp)->get(),
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
