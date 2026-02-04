<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reçu #{{ $paiement->numero_recu }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 12px;
            color: #333;
            padding: 20px;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 11px;
            color: #666;
            margin: 3px 0;
        }
        
        .receipt-info {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }
        
        .receipt-info-left,
        .receipt-info-right {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }
        
        .receipt-info-right {
            text-align: right;
        }
        
        .info-label {
            font-size: 10px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 15px;
        }
        
        .client-info {
            background-color: #f5f5f5;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        
        .client-info h3 {
            font-size: 11px;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 10px;
        }
        
        .client-info p {
            margin: 5px 0;
        }
        
        .prestations-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .prestations-table thead {
            background-color: #333;
            color: white;
        }
        
        .prestations-table th,
        .prestations-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .prestations-table th {
            font-size: 11px;
            text-transform: uppercase;
        }
        
        .prestations-table td {
            font-size: 12px;
        }
        
        .prestations-table td.text-right {
            text-align: right;
        }
        
        .prestations-table td.text-center {
            text-align: center;
        }
        
        .totals {
            margin-top: 20px;
            text-align: right;
        }
        
        .total-row {
            display: flex;
            justify-content: flex-end;
            margin: 8px 0;
            padding: 5px 0;
        }
        
        .total-label {
            width: 200px;
            text-align: right;
            padding-right: 20px;
        }
        
        .total-value {
            width: 150px;
            text-align: right;
            font-weight: bold;
        }
        
        .grand-total {
            border-top: 2px solid #333;
            padding-top: 10px;
            margin-top: 10px;
        }
        
        .grand-total .total-value {
            font-size: 18px;
            color: #333;
        }
        
        .payment-method {
            background-color: #f0f8ff;
            padding: 10px 15px;
            margin: 20px 0;
            border-left: 4px solid #2196F3;
        }
        
        .gratuit-badge {
            background-color: #4CAF50;
            color: white;
            padding: 10px 15px;
            margin: 20px 0;
            text-align: center;
            font-weight: bold;
            border-radius: 5px;
        }
        
        .footer {
            margin-top: 40px;
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }
        
        .footer p {
            font-size: 11px;
            color: #666;
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- En-tête -->
        <div class="header">
            <h1>{{ $salon['nom'] }}</h1>
            <p>{{ $salon['adresse'] }}</p>
            <p>Tél: {{ $salon['telephone'] }}</p>
        </div>
        
        <!-- Informations du reçu -->
        <div class="receipt-info">
            <div class="receipt-info-left">
                <div class="info-label">Numéro de reçu</div>
                <div class="info-value">{{ $paiement->numero_recu }}</div>
                
                <div class="info-label">Numéro de passage</div>
                <div class="info-value">#{{ $passage->numero_passage ?? $passage->id }}</div>
            </div>
            <div class="receipt-info-right">
                <div class="info-label">Date</div>
                <div class="info-value">{{ $paiement->date_paiement->format('d/m/Y H:i') }}</div>
            </div>
        </div>
        
        <!-- Informations client -->
        <div class="client-info">
            <h3>Informations du client</h3>
            <p><strong>{{ $client->nom_complet }}</strong></p>
            <p>Téléphone: {{ $client->telephone }}</p>
        </div>
        
        <!-- Prestations -->
        <table class="prestations-table">
            <thead>
                <tr>
                    <th>Prestation</th>
                    <th class="text-center">Quantité</th>
                    <th class="text-right">Prix unitaire</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($prestations as $prestation)
                <tr>
                    <td>{{ $prestation->libelle }}</td>
                    <td class="text-center">{{ $prestation->pivot->quantite }}</td>
                    <td class="text-right">{{ number_format($prestation->pivot->prix_applique, 0, ',', ' ') }} FCFA</td>
                    <td class="text-right">{{ number_format($prestation->pivot->prix_applique * $prestation->pivot->quantite, 0, ',', ' ') }} FCFA</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        
        <!-- Totaux -->
        <div class="totals">
            <div class="total-row">
                <div class="total-label">Montant total:</div>
                <div class="total-value">{{ number_format($paiement->montant_total, 0, ',', ' ') }} FCFA</div>
            </div>
            
            <div class="total-row grand-total">
                <div class="total-label">Montant payé:</div>
                <div class="total-value">{{ number_format($paiement->montant_paye, 0, ',', ' ') }} FCFA</div>
            </div>
        </div>
        
        <!-- Mode de paiement -->
        <div class="payment-method">
            <strong>Mode de paiement:</strong> 
            @switch($paiement->mode_paiement)
                @case('especes')
                    Espèces
                    @break
                @case('mobile_money')
                    Mobile Money
                    @break
                @case('carte')
                    Carte bancaire
                    @break
                @default
                    {{ ucfirst($paiement->mode_paiement) }}
            @endswitch
        </div>
        
        <!-- Badge gratuit -->
        @if($passage->est_gratuit)
        <div class="gratuit-badge">
            ✓ PASSAGE GRATUIT - PROGRAMME DE FIDÉLITÉ
        </div>
        @endif
        
        <!-- Pied de page -->
        <div class="footer">
            <p><strong>Merci pour votre visite !</strong></p>
            <p>Ce reçu est un document officiel</p>
        </div>
    </div>
</body>
</html>