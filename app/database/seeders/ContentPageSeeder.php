<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Content\Models\ContentPage;
use Illuminate\Database\Seeder;

final class ContentPageSeeder extends Seeder
{
    public function run(): void
    {
        $pages = [
            [
                'slug' => 'terms',
                'title' => 'Direct Seller Agreement & Terms of Service',
                'meta_description' => 'The binding agreement between Arovolife Private Limited and its Direct Sellers.',
                'body' => <<<'HTML'
<h2>1. Introduction</h2>
<p>This Direct Seller Agreement ("Agreement") is entered into between <strong>Arovolife Private Limited</strong> (CIN U46909TS2026PTC210896) and the individual ("Direct Seller") whose details are captured during registration. This Agreement is governed by the Consumer Protection (Direct Selling) Rules, 2021 ("DSR 2021").</p>

<h2>2. No Joining Fee</h2>
<p>Registration as a Direct Seller with arovolife is <strong>free of cost</strong>. No payment is required at the time of registration. Any person or entity demanding payment for registration is not authorised by arovolife.</p>

<h2>3. Income Source</h2>
<p>Commissions, bonuses and rewards are payable <strong>only on product sales</strong> to end consumers. No income is earned by recruiting other Direct Sellers.</p>

<h2>4. Cooling-Off Period</h2>
<p>Every Direct Seller is entitled to a <strong>30-day cooling-off period</strong> from the Effective Date. During this period, the Direct Seller may cancel the Agreement with one click and receive a full refund of any purchases.</p>

<h2>5. One Identity per PAN</h2>
<p>One individual may hold only one arovolife Distributor Number (ADN). Multiple registrations under the same PAN are prohibited.</p>

<h2>6. Termination</h2>
<p>arovolife may terminate this Agreement for breach of the Code of Ethics, fraudulent activity, or 12 consecutive months of inactivity, subject to seven (7) days' written notice.</p>

<p><em>This document is a Phase 1 placeholder. The final Direct Seller Agreement will be issued before production launch.</em></p>
HTML,
            ],
            [
                'slug' => 'privacy',
                'title' => 'Privacy Policy',
                'meta_description' => 'How arovolife collects, uses and protects your personal data under the DPDP Act 2023.',
                'body' => <<<'HTML'
<h2>1. Scope</h2>
<p>This Privacy Policy explains how Arovolife Private Limited collects, uses, stores and protects personal data, in compliance with the <strong>Digital Personal Data Protection Act, 2023</strong> ("DPDP Act").</p>

<h2>2. Data We Collect</h2>
<ul>
    <li>Identity: full name, date of birth, PAN (stored as hash + last 4 digits only), state of residence.</li>
    <li>Contact: email address, mobile number, residential address.</li>
    <li>Financial: bank account number (encrypted at rest), IFSC code.</li>
    <li>Aadhaar: a reference token returned by a UIDAI-approved AUA/KUA partner, plus the last 4 digits. <strong>Raw Aadhaar numbers are never stored.</strong></li>
</ul>

<h2>3. How We Use Your Data</h2>
<p>Personal data is used solely for (a) identity verification, (b) issuance and operation of your arovolife Distributor Number, (c) processing commissions and payouts, and (d) meeting statutory obligations.</p>

<h2>4. Data Retention</h2>
<p>Records required by DSR 2021 are retained for eight (8) years. You may request erasure of any data held beyond statutory retention periods by writing to our Data Protection Officer.</p>

<h2>5. Grievance Officer</h2>
<p>For any privacy-related concern, write to <strong>privacy@arovolife.com</strong>.</p>

<p><em>This document is a Phase 1 placeholder. The final Privacy Policy will be issued before production launch.</em></p>
HTML,
            ],
            [
                'slug' => 'ethics',
                'title' => 'Code of Ethics',
                'meta_description' => 'Ethical standards every arovolife Direct Seller agrees to uphold.',
                'body' => <<<'HTML'
<h2>1. Honesty and Transparency</h2>
<p>Direct Sellers shall represent arovolife products and the compensation plan <strong>truthfully and without exaggeration</strong>. No income projections, guaranteed-earnings statements or misleading claims may be made.</p>

<h2>2. Prohibited Channels</h2>
<ul>
    <li>No listing of arovolife products on e-commerce marketplaces (Amazon, Flipkart, etc.).</li>
    <li>No sale of arovolife products in offline retail stores.</li>
    <li>No recruiting of minors (age 18+; 21+ in Maharashtra).</li>
</ul>

<h2>3. No Income from Recruiting</h2>
<p>Direct Sellers earn only from retail product sales. Recruiting others into the network is not itself a source of income.</p>

<h2>4. Protecting the Brand</h2>
<p>Direct Sellers shall not make unauthorised product claims, especially medical or therapeutic claims not approved by arovolife.</p>

<h2>5. Consequences of Breach</h2>
<p>Breach of this Code may result in account freeze, termination, or legal action. Every breach is reviewed by the arovolife Compliance Committee.</p>

<p><em>This document is a Phase 1 placeholder.</em></p>
HTML,
            ],
            [
                'slug' => 'grievance',
                'title' => 'Grievance Redressal',
                'meta_description' => 'How to raise a complaint with arovolife and the guaranteed response times.',
                'body' => <<<'HTML'
<h2>1. Ways to Reach Us</h2>
<ul>
    <li><strong>Email:</strong> grievance@arovolife.com</li>
    <li><strong>Phone:</strong> to be announced before launch</li>
    <li><strong>Post:</strong> Arovolife Private Limited, Registered Office, India</li>
    <li><strong>Walk-in:</strong> at any arovolife centre during business hours</li>
</ul>

<h2>2. What Happens Next</h2>
<p>Every complaint is assigned a unique grievance ID and tracked against an SLA clock. You will be able to see status transitions in real time.</p>

<h2>3. Service Level Agreements</h2>
<ul>
    <li><strong>Acknowledgement:</strong> within 48 hours of receipt</li>
    <li><strong>Resolution:</strong> within 30 days (most issues resolved in under 14 days)</li>
    <li><strong>Cooling-off refund:</strong> within 7 business days of a valid cancellation request</li>
</ul>

<h2>4. Grievance Officer</h2>
<p>Our designated Grievance Officer is <strong>to be named before production launch</strong>. Email <strong>grievance@arovolife.com</strong> in the interim.</p>

<h2>5. Escalation</h2>
<p>If your grievance is not resolved to your satisfaction, you may escalate to the Central Consumer Protection Authority under the Consumer Protection Act, 2019.</p>

<p><em>This document is a Phase 1 placeholder.</em></p>
HTML,
            ],
        ];

        foreach ($pages as $data) {
            ContentPage::updateOrCreate(
                ['slug' => $data['slug']],
                array_merge($data, [
                    'status' => ContentPage::STATUS_PUBLISHED,
                    'published_at' => now(),
                ]),
            );
        }

        $this->command->info('Seeded '.count($pages).' content pages.');
    }
}
