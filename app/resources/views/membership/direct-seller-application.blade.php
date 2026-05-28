<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Direct Seller Application — arovolife</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @media print {
            @page { size: A4 portrait; margin: 16mm; }
            .no-print { display: none !important; }
            html, body { background: #ffffff !important; }
            body.wizard-stage { background: #ffffff !important; }
            .wizard-stage::before { display: none !important; }
            .dsa-card { box-shadow: none !important; border-color: #d1d5db !important; }
            .dsa-section { break-inside: avoid; }
        }
    </style>
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    {{-- Toolbar (hidden when printing) --}}
    <div class="no-print sticky top-0 z-10 bg-white border-b border-gray-200">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('dashboard') }}" class="text-sm text-gray-600 hover:text-gray-900 whitespace-nowrap">← Dashboard</a>
                <h1 class="text-base sm:text-lg font-bold text-gray-900 truncate">arovolife Direct Seller Application</h1>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="window.print()"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-sm font-medium text-gray-700 transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
                    Download PDF
                </button>
                <button type="button" onclick="window.print()"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-sm font-medium text-white transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z"/></svg>
                    Print
                </button>
            </div>
        </div>
        <p class="max-w-4xl mx-auto px-4 sm:px-6 pb-3 text-xs text-gray-500">
            Both buttons open your browser's print dialog — choose <span class="font-medium">Save as PDF</span> to download.
        </p>
    </div>

    @php
        $rows = [
            ['label' => 'Full name',          'value' => $distributor->full_name ?: $user?->full_name],
            ['label' => 'ADN',                'value' => $distributor->adn,                          'mono' => true],
            ['label' => 'Registration date',  'value' => $distributor->effective_date?->format('d M Y')],
            ['label' => 'Cooling-off ends',   'value' => $distributor->cooling_off_end_at?->format('d M Y')],
            ['label' => 'Email',              'value' => $user?->email],
            ['label' => 'Phone',              'value' => $distributor->phone_e164 ?? $user?->phone_e164],
            ['label' => 'Date of birth',      'value' => $user?->date_of_birth],
            ['label' => 'State',              'value' => $distributor->state],
            ['label' => 'PAN (last 4)',       'value' => $distributor->pan_last4 ? 'XXXXXX'.$distributor->pan_last4.'X' : null, 'mono' => true],
            ['label' => 'Aadhaar (last 4)',   'value' => $distributor->aadhaar_last4 ? 'XXXXXXXX'.$distributor->aadhaar_last4 : null, 'mono' => true],
            ['label' => 'Bank IFSC',          'value' => $distributor->bank_ifsc,                    'mono' => true],
            ['label' => 'Sponsor',            'value' => $sponsor ? trim(($sponsor->user?->full_name ?: 'Distributor').' ('.$sponsor->adn.')') : null],
            ['label' => 'Placement side',     'value' => $distributor->placement_side === 'L' ? 'Left leg' : ($distributor->placement_side === 'R' ? 'Right leg' : null)],
            ['label' => 'Couple registration','value' => $distributor->spouse_distributor_id ? ($distributor->is_primary_couple ? 'Yes — Primary' : 'Yes — Spouse') : 'No'],
            ['label' => 'Account status',     'value' => ucfirst((string) ($distributor->status ?? '—'))],
        ];
    @endphp

    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-8 space-y-8">

        {{-- ── Header card ────────────────────────────────────────────────── --}}
        <div class="dsa-card bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
            <div class="flex items-center gap-4 mb-4">
                <img src="{{ asset('assets/arovolife-logos/arovolife-blue-logo.png') }}" alt="arovolife" class="h-12 w-auto">
                <div>
                    <p class="text-[11px] uppercase tracking-wider text-brand-600 font-semibold">Direct Seller Application</p>
                    <p class="text-sm text-gray-500">Arovolife Private Limited · CIN U46909TS2026PTC210896</p>
                </div>
            </div>
            <p class="text-sm text-gray-600 leading-relaxed">
                This is the record of your registration as an arovolife Direct Seller. The details below
                reflect what is held on file. If any field needs correction, raise a request from
                <a href="{{ route('contact.show') }}" class="text-brand-600 underline">Contact us</a>.
            </p>
        </div>

        {{-- ── Distributor details (tabular) ──────────────────────────────── --}}
        <section class="dsa-section dsa-card bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <header class="px-6 py-4 border-b border-gray-200 bg-gray-50/60">
                <h2 class="text-base font-semibold text-gray-900">Distributor details</h2>
            </header>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    @foreach($rows as $row)
                        <tr>
                            <th scope="row" class="w-1/3 text-left px-6 py-3 font-medium text-gray-600 align-top">{{ $row['label'] }}</th>
                            <td class="px-6 py-3 text-gray-900 align-top {{ ($row['mono'] ?? false) ? 'font-mono tracking-wider' : '' }}">
                                {{ $row['value'] ?: '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        {{-- ── Direct Seller Agreement — verbatim from the source PDF
             (AROVOLIFE DIRECT SELLER AGREEMENT.docx.pdf). Rendered with
             plain semantic HTML so it prints cleanly. --}}
        <section class="dsa-section dsa-card bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-6 py-6 text-[13px] text-gray-800 leading-relaxed space-y-4 dsa-terms">
                <p class="text-center font-bold text-base text-gray-900">AROVOLIFE DIRECT SELLER AGREEMENT</p>
                <p class="text-center font-semibold text-gray-900">TERMS &amp; CONDITIONS</p>

                <p>
                    <strong>Arovolife Private Limited,</strong> (herein, "Arovolife" or "Company") is a company
                    incorporated under The Companies Act, 2013 bearing CIN U46909TS2026PTC210896 and is
                    engaged in marketing and distribution of cosmetics, skin care goods, natural health
                    products, and such other products or services as Arovolife may market from time to time
                    (the "Products"). Sales of these Products are made by independent distributors,
                    appointed pursuant to these Terms and Conditions read with Arovolife Code of Ethics
                    and Principles, Arovolife Compensation Plan and Arovolife Policies and procedures any
                    other document as amended from time to time. These Terms &amp; Conditions are to be read
                    together with the application, company's documents and collectively they constitute a
                    binding agreement between Arovolife and the Direct Seller signing this application.
                    This Contract is a legally binding document and is in accordance with the Protection
                    (Direct Selling) Rules, 2021 issued by the Govt. of India, Ministry of Consumer Affairs,
                    Food &amp; Public Distribution, Department of Consumer Affairs vide FG.S.R. 889(E) dated
                    28th December 2021 ("Direct Selling Rules") read with Indian Contract Act, 1872. This
                    Contract is between the Applicant hereinafter referred to as "Arovolife Direct Seller"
                    and Arovolife.
                </p>

                <h3 class="text-sm font-bold text-gray-900 pt-2">Definitions:</h3>

                <p><strong>a) "Arovolife Direct Seller"</strong><br>
                    means a Person or a business entity (such as a private limited company, limited
                    liability partnership, sole proprietorship or partnership firm) appointed by Arovolife
                    on a principal-to-principal basis under this Agreement to undertake the sale, marketing
                    and distribution of Arovolife Products and services. Any Direct seller of Arovolife may
                    introduce or sponsor another direct seller and support them to build their direct
                    selling business of Arovolife products and services.
                </p>

                <p><strong>b) "cooling-off period"</strong><br>
                    means a period of time given to a participant to cancel the agreement he has entered
                    into for participating in the direct selling business without resulting in any breach
                    of contract or levy of penalty.
                </p>

                <p><strong>c) "Direct Seller Contract"</strong><br>
                    shall mean and include the following and all of which are collectively referred to as
                    the <strong>"Agreement":</strong>
                </p>
                <ul class="list-disc pl-6 space-y-1">
                    <li>The Arovolife Direct Seller Application Form (Business Entity version)</li>
                    <li>These Terms and Conditions forming part of the Arovolife Direct Seller Application Form.</li>
                    <li>The Arovolife Code of Ethics &amp; principles.</li>
                    <li>The Arovolife Business Plan, as amended from time to time which shall be notified on the website (web address).</li>
                </ul>

                <p><strong>d) "Effective Date"</strong><br>
                    means the date on which the Company approves the application submitted by the business entity.
                </p>

                <p><strong>e) "Intellectual Property"</strong><br>
                    includes all licensed copyrights, designs, trademarks, patents, processes and corporate
                    names, computer software licensed by the Company and the goodwill of any licensed
                    business name, secret processes or Confidential Information licensed by the Company.
                </p>

                <p><strong>f) "mis-selling"</strong><br>
                    means selling a product or service by misrepresenting in order to successfully complete
                    a sale and includes providing consumers with misleading information about a product or
                    service or omitting key information about a product or providing information that makes
                    the product appear to be something it is not.
                </p>

                <p><strong>g) "Person"</strong><br>
                    means an individual.
                </p>

                <p><strong>h) "prospect"</strong><br>
                    means a person to whom an offer or a proposal is made by a direct seller to join a
                    direct selling entity.
                </p>

                <p><strong>i) "Pyramid Scheme"</strong><br>
                    means a multi-layered network of subscribers to a scheme formed by subscribers enrolling
                    one or more subscribers in order to receive any benefit, directly or indirectly, as a
                    result of enrolment or action or performance of additional subscribers to the scheme,
                    in which the subscribers enrolling further subscribers occupy a higher position and the
                    enrolled subscribers a lower position, resulting in a multi-layered network of
                    subscribers with successive enrolments.
                </p>

                <p><strong>j) "Saleable":</strong><br>
                    means, with respect to goods and/or services which have been unopened, at the discretion
                    of the company used by not more than 30% (as determined by Arovolife), products which
                    have not expired, are non-seasonal product or products not offered under special
                    promotion.
                </p>

                <p><strong>k) "Territory"</strong><br>
                    shall mean the Republic of India.
                </p>

                {{-- ── 1. Eligibility ─────────────────────────────────────── --}}
                <h3 class="text-sm font-bold text-gray-900 pt-3">1. Eligibility Criteria &amp; Requirements</h3>

                <p>1.1 The applicant as a person confirms and undertakes that he/she is above the age of 18 years and is not disqualified from contracting by any law.</p>
                <p>1.2 The applicant as a business entity confirms and undertakes that it is duly incorporated and validly existing under the applicable laws of India.</p>
                <p>1.3 Applicant must submit the following documents along with filled application form:</p>

                <div class="overflow-x-auto">
                    <table class="w-full text-[12px] border border-gray-300">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="border border-gray-300 px-3 py-2 text-left w-16">Sno</th>
                                <th class="border border-gray-300 px-3 py-2 text-left">Person</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td class="border border-gray-300 px-3 py-2 text-center">1</td><td class="border border-gray-300 px-3 py-2">Duly filled Application form</td></tr>
                            <tr><td class="border border-gray-300 px-3 py-2 text-center">2</td><td class="border border-gray-300 px-3 py-2">Copy of Government issued Identity Card</td></tr>
                            <tr><td class="border border-gray-300 px-3 py-2 text-center">3</td><td class="border border-gray-300 px-3 py-2">Copy of Residential Proof</td></tr>
                            <tr><td class="border border-gray-300 px-3 py-2 text-center">4</td><td class="border border-gray-300 px-3 py-2">Copy of PAN Card</td></tr>
                            <tr><td class="border border-gray-300 px-3 py-2 text-center">5</td><td class="border border-gray-300 px-3 py-2">Cancelled Bank Cheque or Bank Passbook (During Bank Registration)</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-gray-500 italic">*Please use the company provided format for Partner declaration and Board Resolution.</p>

                <p>1.4 Each Direct Seller will be assigned a unique ID, the associates cannot operate through multiple IDs.</p>
                <p>1.5 Each Direct Seller confirms to undergo mandatory orientation covering Company policies, product information, compensation structure, grievance redressal and applicable legal obligations before commencing business activities.</p>
                <p>1.6 The Direct Seller shall, prior to sale, disclose full details to consumers regarding the identity of the Company, product characteristics, price, credit terms, return/refund policy and complaint redressal mechanism.</p>

                {{-- ── 2 – 7 ───────────────────────────────────────────────── --}}
                <p><strong>2. Rejection of application:</strong> Arovolife may reject any application for any reason, at its discretion, if the application contains incomplete, inaccurate, false or misleading information. Any alteration or modification will be subject to verification.</p>

                <p><strong>3. Term:</strong> This Direct selling agreement shall remain valid and continue to remain in full force unless terminated earlier by either party with or without cause by giving a notice.</p>

                <p><strong>4. Joining &amp; Cooling Off Period:</strong> Joining as an Arovolife Direct Seller is Free of Cost, and no person is required to purchase any minimum product or sale promotion material as a condition to join. Commission or incentive to the Arovolife Direct Seller are based on sale of products and no payment will be made for their recruitment. Arovolife Direct Seller understands that they have a cooling off period of 30 days to cancel the contract and receive full refund against the product purchased during this period.</p>

                <p><strong>5. No Employee-Employer relationship:</strong> The Arovolife Direct Seller confirms that it has entered this Contract as an independent contractor or independent business entity operating on a principal-to-principal basis. Nothing in this Contract shall establish an employment relationship, agency, partnership, or joint venture between the Company and the Direct Seller or its representatives.</p>

                <p><strong>6. Duties of Direct Seller:</strong> Direct Seller shall present the Arovolife's Plan and products as set forth in official Arovolife's Literature. Direct Seller shall make no claims regarding potential income, earnings, and products beyond what is stated in official Arovolife literature. Direct Seller shall carry the Identification card issued to him by the Company and will seek prior appointment with customer for initiation of sale. Direct Seller would provide accurate and complete explanations and demonstrations of products, time and place to inspect the sample, and take delivery, prices, credit/payment terms, amount to be paid, return policies, terms of guarantee, after-sales service, products return policy, right to cancel the order, refund policy and complaint redressal mechanism of Arovolife to customers.</p>

                <p><strong>7. Assignment of rights &amp; duties:</strong> This agreement is entered on a personal basis and may not be assigned or transferred by the Arovolife Direct Seller to a third party without the written consent of the company.</p>

                {{-- ── 8. Buy-back / refund matrix ─────────────────────────── --}}
                <p><strong>8. Buy-back and return policy:</strong> Arovolife and the Direct Seller agree to be bound by the terms and conditions of Buy-back/repurchase as mentioned below. The Refund is applicable only for products in Saleable conditions and partially used products (i.e. less than 30%). It is not applicable to products that have been intentionally damaged or misused. Direct Seller may return the products as per below conditions:</p>

                <div class="overflow-x-auto">
                    <table class="w-full text-[12px] border border-gray-300">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="border border-gray-300 px-3 py-2 text-left">Category</th>
                                <th class="border border-gray-300 px-3 py-2 text-left">Condition of Product</th>
                                <th class="border border-gray-300 px-3 py-2 text-left">Period</th>
                                <th class="border border-gray-300 px-3 py-2 text-left">Invoice</th>
                                <th class="border border-gray-300 px-3 py-2 text-left">Payment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="border border-gray-300 px-3 py-2"><strong>a.</strong> During Cooling Off period</td>
                                <td class="border border-gray-300 px-3 py-2">Saleable</td>
                                <td class="border border-gray-300 px-3 py-2">30 days</td>
                                <td class="border border-gray-300 px-3 py-2">Yes</td>
                                <td class="border border-gray-300 px-3 py-2">Direct Seller Price</td>
                            </tr>
                            <tr>
                                <td class="border border-gray-300 px-3 py-2">
                                    <strong>b.</strong> General Buyback /repurchase (During routine business transactions).<br>
                                    <strong>c.</strong> Upon termination of agreement/Contract
                                </td>
                                <td class="border border-gray-300 px-3 py-2"></td>
                                <td class="border border-gray-300 px-3 py-2"></td>
                                <td class="border border-gray-300 px-3 py-2">No</td>
                                <td class="border border-gray-300 px-3 py-2">Direct Seller Price less GST <span class="text-gray-600">(GST will be deducted)</span></td>
                            </tr>
                            <tr>
                                <td class="border border-gray-300 px-3 py-2" rowspan="2">Received in damaged condition</td>
                                <td class="border border-gray-300 px-3 py-2">Saleable</td>
                                <td class="border border-gray-300 px-3 py-2">10 days</td>
                                <td class="border border-gray-300 px-3 py-2">Yes</td>
                                <td class="border border-gray-300 px-3 py-2">Direct Seller Price</td>
                            </tr>
                            <tr>
                                <td class="border border-gray-300 px-3 py-2">Non-Saleable</td>
                                <td class="border border-gray-300 px-3 py-2"></td>
                                <td class="border border-gray-300 px-3 py-2">No</td>
                                <td class="border border-gray-300 px-3 py-2">Direct Seller Price less GST <span class="text-gray-600">(GST will be deducted)</span></td>
                            </tr>
                            <tr>
                                <td class="border border-gray-300 px-3 py-2" rowspan="2">Not completely satisfied with product quality</td>
                                <td class="border border-gray-300 px-3 py-2">Saleable</td>
                                <td class="border border-gray-300 px-3 py-2">30 days</td>
                                <td class="border border-gray-300 px-3 py-2">Yes</td>
                                <td class="border border-gray-300 px-3 py-2">Direct Seller Price</td>
                            </tr>
                            <tr>
                                <td class="border border-gray-300 px-3 py-2">Non-Saleable</td>
                                <td class="border border-gray-300 px-3 py-2"></td>
                                <td class="border border-gray-300 px-3 py-2">No</td>
                                <td class="border border-gray-300 px-3 py-2">Direct Seller Price less GST <span class="text-gray-600">(GST will be deducted)</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                {{-- ── 9. Sales channels ───────────────────────────────────── --}}
                <h3 class="text-sm font-bold text-gray-900 pt-2">9. Restrictions on Sales Channels</h3>

                <p><strong>Permitted Sales:</strong> The Direct Seller is authorized to purchase products from the Company in bulk strictly for the purpose of selling them directly to end-consumers only.</p>

                <p><strong>Prohibited Sales:</strong> The Direct Seller is strictly prohibited from:</p>
                <ul class="list-disc pl-6 space-y-1">
                    <li>Selling, listing, marketing, or offering the Company's products on any e-commerce platform, including but not limited to online marketplaces, third-party websites, or online retail portals;</li>
                    <li>b. Selling or marketing the Company's products through any website, mobile application, or online portal created or operated by the Direct Seller;</li>
                    <li>c. Supplying, reselling, or transferring products to any third party who intends to sell or distribute them online;</li>
                    <li>Displaying, promoting, stocking, or selling the Company's products in any offline retail store, shop, showroom, supermarket, pharmacy, or any other physical retail outlet, whether owned by the Direct Seller or by a third party.</li>
                </ul>

                <p><strong>Compliance Requirement:</strong> The Direct Seller must always ensure full compliance with the above restrictions and must not engage, directly or indirectly, in any activity that violates these terms.</p>

                <p><strong>Consequences of Violation:</strong> Any breach of this clause shall be deemed a material violation of this Agreement. Upon such violation, the Company reserves the right to immediately terminate the Direct Seller Agreement.</p>

                {{-- ── 10 – 22 ────────────────────────────────────────────── --}}
                <p><strong>10. Governing Law:</strong> The Arovolife Direct Seller Contract and all questions of its interpretation shall be governed by and construed in accordance with the laws of Republic of India, without regards to its principles of conflicts of law. The Agreement is civil in nature and hence, it is to be governed and construed in accordance with the Indian Contract Act, 1872, the Code of Civil Procedure and other applicable laws of India.</p>

                <p><strong>11. Consumer Redressal Mechanism:</strong> Pursuant to the Direct Selling guidelines, Arovolife has prepared a step-by-step process for Complaint/Grievance redressal. Complaint/Grievance redressal is made available on Website and customer support centre will also guide direct sellers for any assistance. Arovolife will be liable for grievances arising out of sale of products, services or business opportunity by its Direct Sellers. All complaints received over phone, email, website, post and walk-in shall have a complaint number for tracing and tracking the complaint and record time taken for redressal. Direct Sellers are advised to refer the detailed Grievance redressal policy on website containing the method and process of registering, tracing, tracking, checking and resolution of all complaints.</p>

                <p><strong>12. Modification:</strong> Arovolife may from time to time amend any of the above-mentioned documents by notice on its website. If the Direct Seller does not agree to be bound by the said amendment, he/she may terminate the contract with immediate effect by giving a written notice to Arovolife. Otherwise, Direct Seller's continued relationship with the Company will constitute an affirmative acknowledgment by the Direct Seller to having agreed to such amendment and be bound by same.</p>

                <p><strong>13. Severability:</strong> If any provision of these terms and conditions is declared invalid or unenforceable, the remaining provisions shall remain in full effect.</p>

                <p><strong>14. Dispute Settlement:</strong> Any dispute arising out of this Agreement shall endeavour to settle through mutual discussion within 30 days of such dispute or otherwise shall be referred to a sole arbitrator to be appointed by the director of Arovolife, whose decision shall be binding on the parties according to the provisions of "The Arbitration and Conciliation Act, 1996". The venue of arbitration shall be in New Delhi.</p>

                <p><strong>15. Data Protection:</strong> The Direct Seller acknowledges that the Company may collect and process personal data for the purposes of onboarding, training, compliance, and performance of this Agreement. All collection, use, storage, sharing and protection of personal data shall be governed by the Company's Privacy Notice, available on its official website. The Direct Seller is required to review the Privacy Notice and may direct any data protection related queries or requests to the contact details specified therein.</p>

                <p><strong>16. Intellectual Property and Use of Name/Image:</strong> The Direct Seller grants the Company a non-exclusive licence to use the Direct Seller's name, photograph, likeness, and personal story in its marketing and promotional materials, without any claim to compensation. All trademarks, logos, content and other intellectual property of the Company shall remain its exclusive property, and the Direct Seller shall use such IP solely in the manner authorised by the Company.</p>

                <p><strong>17. Confidentiality:</strong> The Direct Seller shall keep confidential all non-public, proprietary or business information received from the Company and shall not disclose or use such information for any purpose other than fulfilling their obligations under this Agreement.</p>

                <p><strong>18. Force Majeure:</strong> The term force majeure shall include, but not be limited to fires, floods, lightening, disease, acts of God or the public enemy, embargoes, strikes, lockouts, wars (declared or undeclared), riots, civil commotion, interference by civil or military authorities, terrorist acts, Government actions, order(s) or request(s), including (without limitation) certification, clearance or other document, or any other cause or contingency beyond the control of Arovolife in any of the aforesaid events. Arovolife shall not be liable for failure to perform or any delay in performance of services when and to extent that such failure or delay is due to force majeure. If the duration of Force Majeure exceeds thirty (30) days, either Party may be entitled to terminate this Agreement upon prior written notice to the other Party.</p>

                <p><strong>19. Waiver:</strong> Any waiver by the Company of any breach of this Agreement must be in writing and signed by an authorized officer of the Company. However, such waiver shall not operate or be construed as a waiver of any subsequent breach thereafter.</p>

                <p><strong>20. Limitation of liability:</strong> Company's liability whether under agreement or otherwise, arising out of or in connection with this agreement shall not exceed the lesser of (a) actual damages or loss assessed by the arbitrator (b) the total commission earned by the Arovolife Direct Seller during the six months period preceding the date of the dispute.</p>

                <p><strong>21. Termination:</strong> Both parties hereby agree that this Agreement may be terminated by either party, for any reason other than those specifically listed herein, by providing written notice to the other party. In cases where an Arovolife Direct Seller has not made any sale of goods or services for a continuous period of up to one (1) year from the date of execution of this Agreement or from the date of the last sale made, the Company shall have the right to terminate this Agreement by giving seven (7) days' written notice to the Arovolife Direct Seller. The Company may also terminate this Agreement immediately if the Arovolife Direct Seller is found to be in violation of the Company's Policies and Procedures or these Terms and Conditions.</p>

                <p><strong>22. Service of Notices:</strong> Any notice required to be served by either Party to the other under this Agreement shall be deemed to be duly served if in the case of Arovolife, it is delivered by hand or registered post at the following address:</p>

                <p class="pl-4 italic">
                    Arovolife Pvt. Ltd. — 6-51/2, Bank Colony, Pothireddypally, Sangareddy, Dist: Sangareddy — 502001, Telangana, India.
                </p>

                <p>And in the case of Direct Seller, if the notice is delivered by hand or sent by registered post at the address available in the database of Arovolife as updated from time to time based upon the request from Direct Seller issued in this behalf to Arovolife.</p>

                {{-- ── Declaration ────────────────────────────────────────── --}}
                <h3 class="text-sm font-bold text-gray-900 pt-3">Declaration</h3>
                <p>I hereby accept all the terms and conditions of this Agreement and understand that I would be bound by the same.</p>

                <div class="pt-4 space-y-2 text-sm">
                    <p><strong>Name of the Direct Seller:</strong>
                        <span class="ml-2">{{ $distributor->full_name ?: $user?->full_name ?: '__________________________' }}</span>
                    </p>
                    <p><strong>Signatures:</strong> <span class="ml-2 text-gray-500">__________________________</span></p>
                    <p><strong>Date:</strong> <span class="ml-2">{{ $distributor->effective_date?->format('d M Y') ?: '____________' }}</span></p>
                    <p><strong>Place:</strong> <span class="ml-2">{{ $distributor->state ?: '____________' }}</span></p>
                </div>
            </div>
        </section>

        <p class="text-center text-[11px] text-slate-400">
            Arovolife Private Limited &mdash; CIN U46909TS2026PTC210896
        </p>
    </div>

</body>
</html>
