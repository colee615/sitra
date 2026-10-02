<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $formTitle }} - {{ $code }}</title>
    <link rel="stylesheet" href="{{ asset('css/postal-cn23.css') }}">
</head>
<body>
<nav class="print-toolbar" aria-label="Print controls">
    <a href="{{ route('postal.cds', ['codigo' => $code]) }}">Back to shipment</a>
    <span>Reconstructed preview from CDS / IPS records; not the original official PDF.</span>
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
</nav>

<main class="cn23-sheet">
    <header class="form-header">
        <div class="brand-block">
            <svg class="ems-logo" viewBox="0 0 390 42" preserveAspectRatio="none" role="img" aria-label="EMS">
                <g fill="#ff7300">
                    <path d="M0 3h34l-5 3H8zM4 8h30l-5 3H12zM8 13h26l-5 3H16zM12 18h21l-5 3H20z" />
                    <path d="M123 4h267v4H123zM123 13h267v4H123zM123 22h267v4H123z" />
                </g>
                <text x="32" y="39" textLength="82" lengthAdjust="spacingAndGlyphs" fill="#0035ad" font-family="Arial,Helvetica,sans-serif" font-size="44" font-style="italic" font-weight="900" letter-spacing="-3">EMS</text>
                <path d="M30 9h87M30 15h87M30 21h87M30 27h87M30 33h87" stroke="#fff" stroke-width="1.5" />
            </svg>
            <h1>Customs declaration</h1>
            <span class="operator-label">Name of the designated operator</span>
            <strong class="operator-name">{{ $operatorCode ?? '' }}</strong>
        </div>

        <div class="barcode-block">
            @if(preg_match('/^[A-Z0-9-]{1,35}$/D', $code))
                <div class="barcode">{!! \Milon\Barcode\Facades\DNS1DFacade::getBarcodeHTML($code, 'C128', 1.7, 46) !!}</div>
            @endif
            <div class="tracking-code">{{ implode(' ', str_split($code)) }}</div>
            <div class="may-open">May be opened officially</div>
        </div>

        <div class="form-type"><strong>CN 23</strong> @if($mailClass === 'E')<span>EMS</span>@endif</div>

        <div class="importer-reference">
            <div class="importer-field">
                <span>Importer's reference (if any) (tax code/VAT No./importer code) (optional)</span>
                <strong>{{ $recipient['customs_reference'] ?? '' }}</strong>
            </div>
            <div class="importer-field">
                <span>Importer's telephone/fax/e-mail (if known)</span>
                <strong>{{ collect([$recipient['phone'] ?? null, $recipient['email'] ?? null])->filter()->implode(' / ') }}</strong>
            </div>
        </div>
    </header>

    <section class="party-grid" aria-label="Sender and recipient">
        <article class="party-side sender-side">
            <span class="side-label">From</span>
            <div class="sender-fields">
                <div class="party-cell sender-name"><span>Name</span><strong>{{ $sender['name'] ?? '' }}</strong></div>
                <div class="party-cell sender-street"><span>Street</span><strong>{{ $sender['address'] ?? '' }}</strong></div>
                <div class="party-cell sender-customs-reference"><span>Sender's customs reference (if any)</span><strong>{{ $sender['customs_reference'] ?? '' }}</strong></div>
                <div class="party-cell sender-post-city"><span>Postcode</span><strong>{{ $sender['postcode'] ?? '' }}</strong><span class="city-label">City</span><strong>{{ $sender['city'] ?? '' }}</strong></div>
                <div class="party-cell sender-country"><span>Country</span><strong>{{ $sender['country_code'] ?? '' }}{{ !empty($sender['country']) ? ' ('.mb_strtoupper($sender['country'], 'UTF-8').')' : '' }}</strong><strong class="state-value">{{ $sender['state'] ?? '' }}</strong></div>
                <div class="party-cell sender-contact"><span>Tel.</span><strong>{{ $sender['phone'] ?? '' }}</strong><span class="email-label">E-mail</span><strong>{{ $sender['email'] ?? '' }}</strong></div>
            </div>
        </article>

        <article class="party-side recipient-side">
            <span class="side-label">To</span>
            <div class="recipient-fields">
                <div class="party-cell"><span>Name</span><strong>{{ $recipient['name'] ?? '' }}</strong></div>
                <div class="party-cell"><span>Street</span><strong>{{ $recipient['address'] ?? '' }}</strong></div>
                <div class="party-cell recipient-post-city"><span>Postcode</span><strong>{{ $recipient['postcode'] ?? '' }}</strong><span class="city-label">City</span><strong>{{ $recipient['city'] ?? '' }}</strong></div>
                <div class="party-cell recipient-country"><span>Country</span><strong>{{ $recipient['country_code'] ?? '' }}{{ !empty($recipient['country']) ? ' ('.mb_strtoupper($recipient['country'], 'UTF-8').')' : '' }}</strong><strong class="state-value">{{ $recipient['state'] ?? '' }}</strong></div>
                <div class="party-cell recipient-contact"><span>Tel.</span><strong>{{ $recipient['phone'] ?? '' }}</strong><span class="email-label">E-mail</span><strong>{{ $recipient['email'] ?? '' }}</strong></div>
            </div>
        </article>
    </section>

    @php($visiblePieces = collect($pieces)->take(5))
    <section class="contents-section" aria-label="Detailed description of contents">
        <table class="contents-table">
            <colgroup><col class="description-col"><col class="quantity-col"><col class="weight-col"><col class="value-col"><col class="tariff-col"><col class="origin-col"></colgroup>
            <thead>
                <tr>
                    <th rowspan="2">Detailed description of contents</th>
                    <th rowspan="2">Quantity</th>
                    <th rowspan="2">Net weight<br>(in kg)</th>
                    <th rowspan="2">Value</th>
                    <th colspan="2" class="commercial-heading">For commercial items only</th>
                </tr>
                <tr><th>HS tariff number</th><th>Country of origin of goods</th></tr>
            </thead>
            <tbody>
                @foreach($visiblePieces as $piece)
                    <tr class="item-row">
                        <td>{{ $piece['description'] ?? '' }}</td>
                        <td class="number-cell">{{ $piece['quantity'] ?? '' }}</td>
                        <td class="number-cell">{{ $piece['net_weight'] ?? '' }}</td>
                        <td class="number-cell item-value">{{ $piece['value'] ?? '' }}</td>
                        <td>{{ $piece['tariff'] ?? '' }}</td>
                        <td class="origin-cell">{{ $piece['origin_code'] ?? '' }}{{ !empty($piece['origin']) ? ' ('.mb_strtoupper($piece['origin'], 'UTF-8').')' : '' }}</td>
                    </tr>
                @endforeach
                @for($blank = $visiblePieces->count(); $blank < 5; $blank++)
                    <tr class="blank-row"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>
                @endfor
            </tbody>
            <tfoot>
                <tr><td colspan="2"></td><td>Total gross weight<br><strong>{{ $grossWeight ?? '' }}</strong></td><td>Total value<br><strong>{{ $declaredValue ?? '' }}</strong></td><td colspan="2"></td></tr>
            </tfoot>
        </table>
    </section>

    <div class="lower-grid">
        <div class="lower-left">
            <section class="category-section">
                <div class="category-column category-first">
                    <div class="category-title">Category of item</div>
                    <div class="category-option"><i>{{ $category === 'Regalo' ? 'X' : '' }}</i>Gift</div>
                    <div class="category-option"><i>{{ $category === 'Documentos' ? 'X' : '' }}</i>Documents</div>
                </div>
                <div class="category-column category-second">
                    <div class="category-title">&nbsp;</div>
                    <div class="category-option"><i>{{ $category === 'Muestra comercial' ? 'X' : '' }}</i>Commercial sample</div>
                    <div class="category-option"><i>{{ $category === 'Returned goods' ? 'X' : '' }}</i>Returned goods</div>
                    <div class="category-option"><i>{{ $category === 'Otro' ? 'X' : '' }}</i>Other</div>
                </div>
                <div class="category-column category-third">
                    <div class="category-title">&nbsp;</div>
                    <div class="category-option"><i>{{ $category === 'Sales of goods' ? 'X' : '' }}</i>Sales of goods</div>
                    <div class="explanation"><span>Explanation:</span><strong>{{ $category === 'Otro' ? ($natureDescription ?? '') : '' }}</strong></div>
                </div>
            </section>

            <section class="comments-section"><span>Comments</span><small>(e.g.: goods subject to quarantine, sanitary/phytosanitary inspection or other restrictions)</small><strong>{{ $comments ?? '' }}</strong></section>

            <section class="documents-section">
                <article class="document-cell"><span class="document-heading"><i>{{ !empty($documentGroups['licenses']) ? 'X' : '' }}</i>Licence</span><span class="document-subtitle">No(s). of licence(s)</span><strong>{{ implode(', ', $documentGroups['licenses'] ?? []) }}</strong></article>
                <article class="document-cell"><span class="document-heading"><i>{{ !empty($documentGroups['certificates']) ? 'X' : '' }}</i>Certificate</span><span class="document-subtitle">No(s). of certificate(s)</span><strong>{{ implode(', ', $documentGroups['certificates'] ?? []) }}</strong></article>
                <article class="document-cell"><span class="document-heading"><i>{{ !empty($documentGroups['invoices']) ? 'X' : '' }}</i>Invoice</span><span class="document-subtitle">No. of invoice</span><strong>{{ implode(', ', $documentGroups['invoices'] ?? []) }}</strong></article>
                @if(!empty($otherDocuments))<div class="other-documents">Other documents: {{ implode('; ', $otherDocuments) }}</div>@endif
            </section>

            <section class="certification-section">
                <p>I certify that the particulars given in this customs declaration are correct and that this item does not contain any dangerous article or articles prohibited by legislation or by postal or customs regulations.</p>
                <div class="certification-fields">
                    <div class="date-signature"><span>Date and signature</span><strong>{{ trim(($declarationDate ?? '').' '.($declarationTime ?? '')) }}</strong></div>
                    <div class="declaration-id"><span>Declaration ID</span><strong>{{ $declarationNumber ?? '' }}</strong></div>
                    <div class="sender-signature"><span></span></div>
                </div>
            </section>
        </div>

        <aside class="lower-right">
            <section class="acceptance-section">
                <h2>Acceptance information</h2>
                <div class="acceptance-row"><span>Item weight (kg)</span><strong>{{ $postalWeight ?? '' }}</strong></div>
                <div class="acceptance-row"><span>Postal charges/Fees</span><strong>{{ $postalCharges ?? '' }}</strong></div>
                <div class="acceptance-row"><span>Insurance</span><strong>{{ $insurance ?? '' }}</strong></div>
                <div class="acceptance-row"><span>Total</span><strong></strong></div>
                <div class="acceptance-row"><span>Office</span><strong>{{ $acceptanceOffice ?? '' }}</strong></div>
                <div class="acceptance-date"><span>Date</span><strong>{{ trim(($acceptanceDate ?? '').' '.($acceptanceTime ?? '')) }}</strong><span>Time</span><strong></strong></div>
            </section>
            <section class="delivery-section">
                <h2>Delivery information</h2>
                <div class="delivery-date"><span>Date</span><strong>{{ $deliveryDate ?? '' }}</strong><span>Time</span><strong>{{ $deliveryTime ?? '' }}</strong></div>
                <div class="delivery-person"><span>Person name</span><strong>{{ $delivery->SIGNATORY_NM ?? '' }}</strong></div>
                <div class="delivery-signature"><span>Signature</span></div>
            </section>
        </aside>
    </div>

    <small class="form-copyright">© Universal Postal Union, CDS</small>
</main>

@if(collect($pieces)->count() > 5)
    <section class="cn23-continuation">
        <h1>CN 23 - Continuation sheet</h1>
        <p>Shipment code: <strong>{{ $code }}</strong></p>
        <table class="continuation-table"><thead><tr><th>Detailed description</th><th>Quantity</th><th>Net weight (kg)</th><th>Value</th><th>HS tariff number</th><th>Country of origin</th></tr></thead><tbody>
        @foreach(collect($pieces)->skip(5) as $piece)
            <tr><td>{{ $piece['description'] ?? '' }}</td><td>{{ $piece['quantity'] ?? '' }}</td><td>{{ $piece['net_weight'] ?? '' }}</td><td>{{ $piece['value'] ?? '' }}</td><td>{{ $piece['tariff'] ?? '' }}</td><td>{{ $piece['origin_code'] ?? '' }}{{ !empty($piece['origin']) ? ' ('.mb_strtoupper($piece['origin'], 'UTF-8').')' : '' }}</td></tr>
        @endforeach
        </tbody></table>
    </section>
@endif
</body>
</html>
