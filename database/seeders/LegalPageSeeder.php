<?php

namespace Database\Seeders;

use App\Models\LegalPage;
use Illuminate\Database\Seeder;

/**
 * The six legal / compliance pages (/legal/{slug}). Safe in production and re-runnable: a page
 * that already exists (matched by slug) is left exactly as edited in the admin panel.
 */
class LegalPageSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::pages() as $i => $page) {
            LegalPage::firstOrCreate(['slug' => $page['slug']], [...$page, 'sort_order' => ($i + 1) * 10, 'is_published' => true]);
        }

        $this->command?->info('Legal pages ready: '.LegalPage::count().'.');
    }

    /** @return list<array{slug: string, title: string, seo_title: string, seo_description: string, content: string}> */
    public static function pages(): array
    {
        $company = config('provoke.company.name');
        $descriptor = config('provoke.company.billing_descriptor');
        $email = config('provoke.company.support_email');
        $brand = config('provoke.brand.name');
        $site = config('provoke.frontend.url');

        return [
            [
                'slug' => 'terms-of-service',
                'title' => 'Terms of Service',
                'seo_title' => "Terms of Service | {$brand}",
                'seo_description' => "The terms that apply when you use {$brand} and buy digital or physical card games from {$company}.",
                'content' => <<<MD
These Terms of Service ("Terms") govern your use of {$site} (the "Site") and every purchase you make on it. The Site and the {$brand} brand are operated by **{$company}** ("we", "us", "our"). By using the Site or placing an order, you agree to these Terms.

## 1. Adults only (18+)

The Site and its products are intended exclusively for adults. You may use the Site and make purchases only if you are at least **18 years old**, or the age of majority where you live if that is higher. By using the Site you confirm that you meet this requirement and that viewing adult-oriented, sexually suggestive content is legal where you are. We may close any account we reasonably believe belongs to a minor.

## 2. Your account

You are responsible for keeping your login details confidential and for all activity under your account. Give accurate information and keep it up to date. Accounts are personal and may not be shared, sold or transferred.

## 3. Products

- **Digital decks** are licensed, not sold. A purchase gives you a personal, non-transferable, non-exclusive licence to use the deck through your account for private, non-commercial use. You may not copy, resell, publish, share or redistribute the content.
- **Physical products** (printed boxed games and other goods) are made to order and shipped as described in our [Shipping Policy](/legal/shipping-policy).
- We describe products as accurately as we can; colours and packaging may vary slightly from images.

## 4. Prices, payment and billing

Prices are shown in US dollars and include any discounts displayed at checkout. Taxes and shipping, where applicable, are shown before you pay. Payments are processed by our third-party payment processors; we never store your full card details.

Charges appear on your card or bank statement as **{$descriptor}**.

All purchases are one-time payments unless clearly stated otherwise at checkout. There are no hidden recurring charges.

## 5. Refunds and returns

Refunds and returns are handled under our [Refund & Return Policy](/legal/refund-policy). In short: unopened physical boxed games can be returned within 7 days of delivery; digital products are non-refundable once access has been granted.

If you have a problem with a charge, please contact us at [{$email}](mailto:{$email}) before contacting your bank: we resolve most issues within one business day.

## 6. Acceptable use

You agree not to: use the Site if you are under 18; use the content to harass, coerce or harm anyone; attempt to access other users' accounts or our systems; scrape, copy or redistribute content; or use the Site for any unlawful purpose. All activities suggested by our products are intended only for consenting adults. Consent can be withdrawn at any time, and you are responsible for how you use the content.

## 7. Intellectual property

All content on the Site, including card text, artwork, logos and the {$brand} name, belongs to {$company} or its licensors and is protected by copyright and trademark law.

## 8. Disclaimer and limitation of liability

Our products are for entertainment and relationship enrichment. They are not medical, psychological or legal advice. To the fullest extent permitted by law, the Site and products are provided "as is", and {$company} is not liable for indirect, incidental or consequential damages arising from your use of them. Our total liability for any claim is limited to the amount you paid for the product concerned. Nothing in these Terms limits rights you have under consumer protection laws that cannot be excluded.

## 9. Termination

We may suspend or close accounts that break these Terms. You may close your account at any time by contacting us.

## 10. Changes to these Terms

We may update these Terms from time to time. The version published on this page applies to orders placed after it is published.

## 11. Contact

{$company}  
Email: [{$email}](mailto:{$email})
MD,
            ],
            [
                'slug' => 'privacy-policy',
                'title' => 'Privacy Policy',
                'seo_title' => "Privacy Policy | {$brand}",
                'seo_description' => "How {$company} collects, uses and protects your personal data, and our discreet packaging guarantee.",
                'content' => <<<MD
Your privacy matters to us, especially given the intimate nature of our products. This policy explains what personal data **{$company}** ("we", "us") collects through {$site}, why, and the choices you have.

## Information we collect

- **Account details:** your name, email address and password (stored only in encrypted, hashed form).
- **Order details:** the products you buy, your shipping address and phone number for physical orders, and your order history.
- **Payment details:** handled entirely by our payment processors. We never see or store your full card number.
- **Messages:** what you send us through the contact form or by email.
- **Technical data:** IP address, browser type and basic usage data, used to keep the Site secure and working. Where you consent, we also use analytics and advertising cookies.

## How we use it

We use your data to provide your account and digital library, process and ship orders, send order and account emails, answer your questions, prevent fraud and abuse, and meet our legal obligations. We send marketing emails only if you have opted in, and you can unsubscribe at any time.

## Discreet by design

- **100% discreet, unbranded packaging:** every physical order ships in a plain black box with no {$brand} branding, no product names and no description of the contents on the outside.
- **Neutral billing:** charges appear on your statement as **{$descriptor}**, with no reference to the nature of the products.
- **Neutral emails:** order emails use discreet subject lines.

## Sharing

We never sell your personal data. We share it only with the service providers needed to run the Site: payment processors, our print-on-demand and shipping partners (name and shipping address only), our email delivery provider and our hosting provider. All of them are bound to protect it. We may disclose data if required by law.

## Retention

We keep account data while your account is open, and order records for as long as tax and accounting laws require. Contact form messages are kept for up to 24 months.

## Security

Data is transmitted over encrypted (HTTPS) connections, passwords are hashed, and access to personal data is limited to staff who need it.

## Your rights

Depending on where you live (including under the GDPR and US state privacy laws), you may have the right to access, correct, delete or export your data, and to object to or restrict certain processing. To make a request, email [{$email}](mailto:{$email}). We will answer within 30 days.

## Children

The Site is strictly for adults aged 18 and over. We do not knowingly collect data from anyone under 18; if we learn we have, we delete it.

## Changes

We may update this policy. The current version is always published on this page.

## Contact

{$company}  
Email: [{$email}](mailto:{$email})
MD,
            ],
            [
                'slug' => 'refund-policy',
                'title' => 'Refund & Return Policy',
                'seo_title' => "Refund & Return Policy | {$brand}",
                'seo_description' => 'Unopened physical boxed games can be returned within 7 days. Digital decks are non-refundable once delivered.',
                'content' => <<<MD
We want you to be happy with your purchase. This policy explains when **{$company}** can accept returns and issue refunds.

## Physical boxed games

- You may return a physical boxed game within **7 days of delivery**, provided the **packaging and seal are unopened** and the product is in its original condition.
- For hygiene and content-protection reasons, opened or unsealed games cannot be returned unless they arrived damaged or defective.
- To start a return, email [{$email}](mailto:{$email}) with your order number. We will send you return instructions. Please do not send items back without contacting us first.
- Return shipping costs are paid by the customer, unless the item arrived damaged, defective or incorrect.
- Once we receive and inspect the return, we refund the product price to your original payment method within 5–10 business days. Original shipping charges are non-refundable.

## Damaged, defective or incorrect items

If your order arrives damaged, defective or is not what you ordered, email us within 7 days of delivery with your order number and a photo. We will send a free replacement or a full refund, including shipping.

## Digital products

Digital decks are delivered instantly and unlock immediately in your account. Because digital content cannot be returned, **all sales of digital products are final and non-refundable** once access has been granted. By completing a digital purchase you agree to immediate delivery and acknowledge that you lose any right of withdrawal once the content is made available.

If you cannot access a digital product you paid for, contact us: we will fix the problem or, if we cannot, refund you.

## Duplicate or unauthorised charges

If you were charged twice or do not recognise a charge from **{$descriptor}**, contact us at [{$email}](mailto:{$email}) and we will investigate right away. Duplicate charges are always refunded in full.

## Contact

{$company}  
Email: [{$email}](mailto:{$email})
MD,
            ],
            [
                'slug' => 'shipping-policy',
                'title' => 'Shipping Policy',
                'seo_title' => "Shipping & Delivery Policy | {$brand}",
                'seo_description' => 'Printed to order and shipped worldwide in discreet, unbranded packaging. Processing and delivery times explained.',
                'content' => <<<MD
Our physical card games are **printed on demand** for each order by our fulfilment partners and shipped worldwide in discreet packaging. This policy is provided by **{$company}**.

## Processing time

Each order is printed and packed especially for you. Please allow **3–5 business days** for production before your order ships.

## Delivery time

After dispatch, standard express delivery typically takes **5–10 business days** worldwide. Most orders arrive within **8–15 business days** of being placed in total.

Delivery times are estimates and are not guaranteed. Customs checks, holidays and carrier delays can occasionally add time, particularly for international orders.

## Shipping costs

Shipping costs, if any, are shown at checkout before you pay. Digital products never have shipping costs: they are delivered instantly to your account.

## Tracking

When your order ships, we email you a tracking number so you can follow your parcel.

## Discreet handling

- Every order ships in a **plain, unbranded black box** with no product names or descriptions on the outside.
- The shipping label shows only the information the carrier needs.
- Charges appear on your statement as **{$descriptor}**.

## Customs, duties and taxes

International orders may be subject to import duties or taxes charged by your country. These are not included in our prices and are the recipient's responsibility.

## Address accuracy and failed deliveries

Please check your shipping address carefully. We cannot be responsible for orders shipped to an incorrect address supplied at checkout. If a parcel is returned to us as undeliverable, contact us to arrange reshipment (additional shipping costs may apply).

## Lost or damaged parcels

If your tracking has not updated for 10 business days, or your parcel arrives damaged, email [{$email}](mailto:{$email}) with your order number. We will work with the carrier and send a replacement or refund where appropriate.

## Contact

{$company}  
Email: [{$email}](mailto:{$email})
MD,
            ],
            [
                'slug' => LegalPage::CONTACT_SLUG,
                'title' => 'Contact Us',
                'seo_title' => "Contact & Support | {$brand}",
                'seo_description' => "Questions about an order, a charge or your account? Contact {$brand} support.",
                'content' => <<<MD
Questions about an order, a charge on your statement, or your account? We are here to help, discreetly.

## Customer support

- **Email:** [{$email}](mailto:{$email})
- **Business hours:** Monday to Friday, 9:00 am – 5:00 pm (US Eastern Time)
- **Response time:** we answer all messages within 1 business day.

For order questions, please include your order number.

## Billing questions

Charges appear on your card or bank statement as **{$descriptor}**. If you do not recognise a charge, contact us first: we can resolve most billing questions immediately.

## Company

{$brand} is operated by **{$company}**.
MD,
            ],
            [
                'slug' => 'age-disclaimer',
                'title' => '18+ Age & Consent Disclaimer',
                'seo_title' => "18+ Age & Consent Disclaimer | {$brand}",
                'seo_description' => "{$brand} is restricted to adults aged 18 and over. Please read our age and consent notice.",
                'content' => <<<MD
## Adults only

This website, operated by **{$company}**, contains adult-oriented, sexually suggestive material intended exclusively for adults. **You must be at least 18 years old**, or the age of majority in your jurisdiction if that is higher, to enter, browse or purchase from this website.

By using this website you confirm that:

- you are at least 18 years old (or the age of majority where you live);
- viewing and purchasing adult-oriented material is legal in your country, state or region;
- you are accessing this material voluntarily, for your own private use, and will not show it to minors;
- you will not hold {$company} responsible for any consequences of your choice to view this material.

If you are under 18, or if adult content is not legal where you are, you must leave this website now.

## Consent first, always

All of our games and suggested activities are intended **only for consenting adults**. Consent must be freely given, informed and enthusiastic, and it can be withdrawn at any moment. Every card can be skipped, and every player can stop the game at any time, without explanation.

Never use our products to pressure, coerce or harm anyone. If you or someone you know needs help, contact your local support services.

## Parental controls

Parents and guardians can block access to adult websites using parental-control tools on their devices and networks.

## Questions

Email [{$email}](mailto:{$email}).
MD,
            ],
            [
                'slug' => 'about-us',
                'title' => 'About Us',
                'seo_title' => "About Us | {$brand}",
                'seo_description' => "{$brand} creates conversation and intimacy card games for couples, delivered with discretion. Owned and operated by {$company}.",
                'content' => <<<MD
**{$brand}** creates card games and intimate lifestyle pieces for couples who want more connection, more play and more honest conversation. The brand is owned and operated by **{$company}**.

## Our philosophy: dark luxury

We believe intimacy deserves the same care as any luxury ritual. Our decks are written like a slow evening: candlelight, card stock, velvet tones and words chosen to open a conversation rather than rush it. Every deck is built around four principles:

- **Consent first, always.** Every card can be skipped, and every player can stop at any time.
- **Crafted, not generated.** Every deck is written and curated by our team, with a clear intensity level from gentle to taboo.
- **For two people, not an audience.** Our games are designed for private use between consenting adults.
- **Beautiful enough to keep.** From the digital card designs to our printed boxes, everything is made to feel like a gift.

## Discreet by design

- Physical orders ship in a **plain, unbranded black box** with no product names or descriptions on the outside.
- Charges appear on your statement as **{$descriptor}**.
- Digital decks unlock instantly in your private account library and can be protected with a vault passcode.

## Who we are

{$brand} is a brand of {$company}. We serve adults aged 18 and over worldwide.

## Talk to us

- **Email:** [{$email}](mailto:{$email})
- **Contact form:** [Contact Us](/legal/contact)

We answer every message within 1 business day.
MD,
            ],
            [
                'slug' => 'brand-standard',
                'title' => 'Brand Standard & Trademark Notice',
                'seo_title' => "Brand Standard & Trademark Notice | {$brand}",
                'seo_description' => "How the {$brand} name is written, who owns it, and how our card decks and content are protected.",
                'content' => <<<MD
This notice sets out the single, official way our brand is presented, and how **{$company}** protects the {$brand} name and content.

## 1. The brand name

The one correct form of the brand name is:

**{$brand}**

- Two words, written in capital letters, separated by a single space.
- In running text the name always appears as "{$brand}", never as "Provoke Now", "ProvokeNow", "Provoke-Now", "PN" or any abbreviation, translation or variation.
- The stylised wordmark (PROVOKE in capitals with *now* in italic lowercase) is used only in our official logo artwork. It is a design treatment of the same name, not a different name.

## 2. Ownership and trademark

{$brand}™, the {$brand} logo and wordmark, and the names of our decks and products are trademarks of **{$company}**. They may not be used in any domain name, social media account, product, advertisement or other material without our prior written permission, and may not be used in any way that suggests an endorsement or connection that does not exist.

## 3. Copyright in our card decks

Every {$brand} card deck, including each card's title, scenario, invitation and consent note, together with the deck descriptions, artwork, card designs, photographs and website content, is an original work protected by copyright and owned by {$company} or its licensors. All rights are reserved.

Purchasing a deck gives you a personal, non-transferable licence to use it privately, as described in our [Terms of Service](/legal/terms-of-service). You may not copy, publish, resell, translate, record, adapt or redistribute any card or deck, in whole or in part, or use our content to train or build any artificial-intelligence model or dataset.

## 4. Billing descriptor

For transparency, every purchase from {$brand} appears on your card or bank statement as:

**{$descriptor}**

This is the legal name of the company that owns and operates {$brand}. If you see this name on your statement and have a question about the charge, contact [{$email}](mailto:{$email}) before contacting your bank.

## 5. Reporting misuse

If you believe someone is using the {$brand} name, logo or content without permission, or is selling counterfeit or copied decks, please tell us at [{$email}](mailto:{$email}).

## 6. Contact

{$company}  
Email: [{$email}](mailto:{$email})
MD,
            ],
        ];
    }
}
