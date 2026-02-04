<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Passage;
use App\Models\Paiement;
use App\Models\Prestation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class StatistiqueController extends Controller
{
    /**
     * Bilan journalier.
     */
    public function journalier(Request $request): JsonResponse
    {
        try {
            $date = $request->get('date', now()->format('Y-m-d'));
            
            // Nombre total de clients du jour
            $nombreClients = Passage::byDate($date)
                ->distinct('client_id')
                ->count('client_id');

            // Chiffre d'affaires encaissé
            $chiffreAffaires = Paiement::byDate($date)
                ->valides()
                ->sum('montant_paye');

            // Nombre de passages gratuits
            $nombreGratuits = Passage::byDate($date)
                ->gratuits()
                ->count();

            // Valeur théorique des gratuités
            $valeurGratuites = Passage::byDate($date)
                ->gratuits()
                ->get()
                ->sum('montant_theorique');

            // Répartition par prestation
            $repartitionPrestations = DB::table('passages')
                ->join('passage_prestation', 'passages.id', '=', 'passage_prestation.passage_id')
                ->join('prestations', 'passage_prestation.prestation_id', '=', 'prestations.id')
                ->whereDate('passages.date_passage', $date)
                ->select(
                    'prestations.libelle',
                    DB::raw('COUNT(*) as nombre'),
                    DB::raw('SUM(passage_prestation.prix_applique * passage_prestation.quantite) as montant')
                )
                ->groupBy('prestations.id', 'prestations.libelle')
                ->orderBy('montant', 'desc')
                ->get();

            // Répartition par mode de paiement
            $repartitionPaiements = Paiement::byDate($date)
                ->valides()
                ->select('mode_paiement', DB::raw('SUM(montant_paye) as total'))
                ->groupBy('mode_paiement')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'date' => $date,
                    'resume' => [
                        'nombre_clients' => $nombreClients,
                        'chiffre_affaires' => round($chiffreAffaires, 2),
                        'nombre_passages_gratuits' => $nombreGratuits,
                        'valeur_gratuites' => round($valeurGratuites, 2),
                        'chiffre_affaires_theorique' => round($chiffreAffaires + $valeurGratuites, 2),
                    ],
                    'repartition_prestations' => $repartitionPrestations,
                    'repartition_paiements' => $repartitionPaiements,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur dans journalier: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des statistiques journalières',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Statistiques sur une période.
     */
    public function periode(Request $request): JsonResponse
    {
        try {
            $dateDebut = $request->get('date_debut', now()->startOfMonth()->format('Y-m-d'));
            $dateFin = $request->get('date_fin', now()->format('Y-m-d'));

            // Chiffre d'affaires par jour
            $caParJour = Paiement::byPeriod($dateDebut, $dateFin)
                ->valides()
                ->select(
                    DB::raw('DATE(date_paiement) as date'),
                    DB::raw('SUM(montant_paye) as montant'),
                    DB::raw('COUNT(*) as nombre_paiements')
                )
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            // Nombre de clients uniques
            $nombreClientsUniques = Passage::byPeriod($dateDebut, $dateFin)
                ->distinct('client_id')
                ->count('client_id');

            // Total passages
            $totalPassages = Passage::byPeriod($dateDebut, $dateFin)->count();

            // Total gratuits
            $totalGratuits = Passage::byPeriod($dateDebut, $dateFin)
                ->gratuits()
                ->count();

            // CA total
            $caTotal = Paiement::byPeriod($dateDebut, $dateFin)
                ->valides()
                ->sum('montant_paye');

            // Top 10 clients
            $topClients = Client::select('clients.*')
                ->join('passages', 'clients.id', '=', 'passages.client_id')
                ->join('paiements', 'passages.id', '=', 'paiements.passage_id')
                ->whereBetween('paiements.date_paiement', [$dateDebut, $dateFin])
                ->groupBy('clients.id')
                ->orderBy(DB::raw('SUM(paiements.montant_paye)'), 'desc')
                ->limit(10)
                ->get()
                ->map(function($client) use ($dateDebut, $dateFin) {
                    return [
                        'id' => $client->id,
                        'nom_complet' => $client->nom_complet,
                        'telephone' => $client->telephone,
                        'nombre_passages' => $client->passages()
                            ->byPeriod($dateDebut, $dateFin)
                            ->count(),
                        'chiffre_affaires' => $client->paiements()
                            ->byPeriod($dateDebut, $dateFin)
                            ->valides()
                            ->sum('montant_paye'),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'periode' => [
                        'date_debut' => $dateDebut,
                        'date_fin' => $dateFin,
                    ],
                    'resume' => [
                        'nombre_clients_uniques' => $nombreClientsUniques,
                        'total_passages' => $totalPassages,
                        'total_passages_gratuits' => $totalGratuits,
                        'chiffre_affaires_total' => round($caTotal, 2),
                    ],
                    'evolution_ca' => $caParJour,
                    'top_clients' => $topClients,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur dans periode: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des statistiques de période',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Statistiques des prestations.
     */
    public function prestations(Request $request): JsonResponse
    {
        try {
            $dateDebut = $request->get('date_debut');
            $dateFin = $request->get('date_fin');

            $query = DB::table('prestations')
                ->leftJoin('passage_prestation', 'prestations.id', '=', 'passage_prestation.prestation_id')
                ->leftJoin('passages', 'passage_prestation.passage_id', '=', 'passages.id');

            if ($dateDebut && $dateFin) {
                $query->whereBetween('passages.date_passage', [$dateDebut, $dateFin]);
            }

            $prestations = $query
                ->select(
                    'prestations.id',
                    'prestations.libelle',
                    'prestations.prix',
                    DB::raw('COUNT(passage_prestation.id) as nombre_utilisations'),
                    DB::raw('SUM(passage_prestation.prix_applique * passage_prestation.quantite) as revenu_total')
                )
                ->groupBy('prestations.id', 'prestations.libelle', 'prestations.prix')
                ->orderBy('nombre_utilisations', 'desc')
                ->get()
                ->map(function($prestation) {
                    return [
                        'id' => $prestation->id,
                        'libelle' => $prestation->libelle,
                        'prix_actuel' => $prestation->prix,
                        'nombre_utilisations' => $prestation->nombre_utilisations ?? 0,
                        'revenu_total' => round($prestation->revenu_total ?? 0, 2),
                        'revenu_moyen' => $prestation->nombre_utilisations > 0 
                            ? round($prestation->revenu_total / $prestation->nombre_utilisations, 2) 
                            : 0,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $prestations,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur dans prestations: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des statistiques de prestations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Statistiques de fidélité.
     */
    public function fidelite(): JsonResponse
    {
        try {
            // Répartition des clients par nombre de passages
            $repartition = Client::select(
                    DB::raw('CASE 
                        WHEN nombre_passages = 0 THEN "0"
                        WHEN nombre_passages BETWEEN 1 AND 3 THEN "1-3"
                        WHEN nombre_passages BETWEEN 4 AND 9 THEN "4-9"
                        WHEN nombre_passages BETWEEN 10 AND 19 THEN "10-19"
                        WHEN nombre_passages >= 20 THEN "20+"
                    END as tranche'),
                    DB::raw('COUNT(*) as nombre_clients')
                )
                ->groupBy('tranche')
                ->get();

            // Clients fidèles (10+ passages)
            $clientsFideles = Client::where('nombre_passages', '>=', 10)
                ->orderBy('nombre_passages', 'desc')
                ->limit(20)
                ->get()
                ->map(function($client) {
                    return [
                        'id' => $client->id,
                        'nom_complet' => $client->nom_complet,
                        'telephone' => $client->telephone,
                        'nombre_passages' => $client->nombre_passages,
                        'chiffre_affaires_total' => $client->chiffre_affaires_total,
                        'derniere_visite' => $client->derniere_visite?->format('d/m/Y'),
                    ];
                });

            // Nouveaux clients (créés dans les 30 derniers jours)
            $nouveauxClients = Client::where('created_at', '>=', now()->subDays(30))
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'repartition_passages' => $repartition,
                    'clients_fideles' => $clientsFideles,
                    'nouveaux_clients_30j' => $nouveauxClients,
                    'total_clients' => Client::count(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur dans fidelite: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des statistiques de fidélité',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Statistiques des coiffeurs.
     */
    public function coiffeurs(Request $request): JsonResponse
    {
        try {
            $dateDebut = $request->get('date_debut', now()->startOfMonth()->format('Y-m-d'));
            $dateFin = $request->get('date_fin', now()->endOfMonth()->format('Y-m-d'));

            // Récupérer tous les coiffeurs actifs
            $coiffeurs = User::where('role', 'coiffeur')
                ->where('actif', true)
                ->orderBy('prenom')
                ->orderBy('nom')
                ->get();

            $statsCoiffeurs = [];

            foreach ($coiffeurs as $coiffeur) {
                // Récupérer toutes les prestations réalisées par ce coiffeur dans la période
                $prestationsStats = DB::table('passage_prestation')
                    ->join('passages', 'passage_prestation.passage_id', '=', 'passages.id')
                    ->join('prestations', 'passage_prestation.prestation_id', '=', 'prestations.id')
                    ->where('passage_prestation.coiffeur_id', $coiffeur->id)
                    ->whereBetween('passages.date_passage', [$dateDebut, $dateFin])
                    ->select(
                        'prestations.id',
                        'prestations.libelle',
                        DB::raw('SUM(passage_prestation.quantite) as nombre'),
                        DB::raw('SUM(
                            CASE 
                                WHEN passages.est_gratuit = 1 THEN 0
                                ELSE passage_prestation.prix_applique * passage_prestation.quantite
                            END
                        ) as montant_total')
                    )
                    ->groupBy('prestations.id', 'prestations.libelle')
                    ->orderBy('nombre', 'desc')
                    ->get();

                // Calculer les statistiques globales
                $nombrePrestations = $prestationsStats->sum('nombre');
                $chiffreAffairesGenere = $prestationsStats->sum('montant_total');

                // Récupérer les clients uniques servis par ce coiffeur
                $clientsUniques = DB::table('passage_prestation')
                    ->join('passages', 'passage_prestation.passage_id', '=', 'passages.id')
                    ->where('passage_prestation.coiffeur_id', $coiffeur->id)
                    ->whereBetween('passages.date_passage', [$dateDebut, $dateFin])
                    ->distinct('passages.client_id')
                    ->count('passages.client_id');

                // Calculer le CA moyen par prestation
                $chiffreAffairesMoyen = $nombrePrestations > 0 
                    ? round($chiffreAffairesGenere / $nombrePrestations, 2) 
                    : 0;

                $statsCoiffeurs[] = [
                    'id' => $coiffeur->id,
                    'prenom' => $coiffeur->prenom,
                    'nom' => $coiffeur->nom,
                    'nom_complet' => $coiffeur->nom_complet,
                    'email' => $coiffeur->email,
                    'nombre_prestations' => (int) $nombrePrestations,
                    'nombre_clients_uniques' => $clientsUniques,
                    'chiffre_affaires_genere' => round($chiffreAffairesGenere, 2),
                    'chiffre_affaires_moyen' => $chiffreAffairesMoyen,
                    'prestations' => $prestationsStats->map(function($prestation) {
                        return [
                            'libelle' => $prestation->libelle,
                            'nombre' => (int) $prestation->nombre,
                            'montant_total' => round($prestation->montant_total, 2),
                        ];
                    })->toArray(),
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $statsCoiffeurs,
                'periode' => [
                    'date_debut' => $dateDebut,
                    'date_fin' => $dateFin,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur dans coiffeurs: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des statistiques des coiffeurs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Dashboard général.
     */
    public function dashboard(): JsonResponse
    {
        try {
            $today = now()->format('Y-m-d');

            $aujourdhui = [
                'ca' => Paiement::byDate($today)->valides()->sum('montant_paye') ?? 0,
                'passages' => Passage::byDate($today)->count() ?? 0,
                'clients' => Passage::byDate($today)->distinct('client_id')->count('client_id') ?? 0,
            ];

            $mois = [
                'ca' => Paiement::whereYear('date_paiement', now()->year)->whereMonth('date_paiement', now()->month)->valides()->sum('montant_paye') ?? 0,
                'passages' => Passage::whereYear('date_passage', now()->year)->whereMonth('date_passage', now()->month)->count() ?? 0,
                'nouveaux_clients' => Client::whereYear('created_at', now()->year)->whereMonth('created_at', now()->month)->count() ?? 0,
            ];

            $global = [
                'total_clients' => Client::count() ?? 0,
                'total_prestations' => Prestation::count() ?? 0,
                'ca_total' => Paiement::valides()->sum('montant_paye') ?? 0,
            ];

            $coiffeursStats = User::where('role', 'coiffeur')->where('actif', true)->get()->map(function($coiffeur) {
                $totalPassages = DB::table('passage_prestation')->where('coiffeur_id', $coiffeur->id)->distinct('passage_id')->count('passage_id');
                $nombrePrestations = DB::table('passage_prestation')->where('coiffeur_id', $coiffeur->id)->sum('quantite') ?? 0;
                $caTotal = DB::table('passage_prestation')->join('passages', 'passage_prestation.passage_id', '=', 'passages.id')->where('passage_prestation.coiffeur_id', $coiffeur->id)->where('passages.est_gratuit', false)->sum(DB::raw('passage_prestation.prix_applique * passage_prestation.quantite')) ?? 0;

                return [
                    'id' => $coiffeur->id,
                    'prenom' => $coiffeur->prenom,
                    'nom' => $coiffeur->nom,
                    'nom_complet' => $coiffeur->nom_complet,
                    'total_passages' => (int) $totalPassages,
                    'nombre_prestations' => (int) $nombrePrestations,
                    'ca_total' => round($caTotal, 2),
                ];
            })->sortByDesc('ca_total')->values()->toArray();

            $derniersPassages = Passage::with(['client', 'prestations'])->orderBy('date_passage', 'desc')->limit(10)->get()->map(function($passage) {
                return [
                    'id' => $passage->id,
                    'numero_passage' => $passage->numero_passage,
                    'date_passage' => $passage->date_passage,
                    'est_gratuit' => (bool) $passage->est_gratuit,
                    'montant_total' => $passage->montant_total ?? 0,
                    'montant_theorique' => $passage->montant_theorique ?? 0,
                    'client' => $passage->client ? [
                        'id' => $passage->client->id,
                        'nom' => $passage->client->nom,
                        'prenom' => $passage->client->prenom,
                        'nom_complet' => $passage->client->nom_complet,
                        'telephone' => $passage->client->telephone,
                    ] : null,
                    'prestations' => $passage->prestations->map(function($prestation) {
                        $coiffeur = null;
                        if ($prestation->pivot && $prestation->pivot->coiffeur_id) {
                            $coiffeurModel = User::find($prestation->pivot->coiffeur_id);
                            if ($coiffeurModel) {
                                $coiffeur = [
                                    'id' => $coiffeurModel->id,
                                    'prenom' => $coiffeurModel->prenom,
                                    'nom' => $coiffeurModel->nom,
                                    'nom_complet' => $coiffeurModel->nom_complet,
                                ];
                            }
                        }
                        
                        return [
                            'id' => $prestation->id,
                            'libelle' => $prestation->libelle,
                            'quantite' => $prestation->pivot->quantite ?? 1,
                            'prix_applique' => $prestation->pivot->prix_applique ?? 0,
                            'coiffeur' => $coiffeur,
                        ];
                    })->toArray(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'aujourdhui' => $aujourdhui,
                    'mois' => $mois,
                    'global' => $global,
                    'coiffeurs_stats' => $coiffeursStats,
                    'derniers_passages' => $derniersPassages,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur dans dashboard: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement du dashboard',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }
}