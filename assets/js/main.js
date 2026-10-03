/**
 * Zipoo Marketing Website - Main JS
 * Bilingual (EN & SW) translation engine, Founding 20 application form handler,
 * mobile drawer, FAQ accordion, and smooth scroll.
 */

(function () {
  'use strict';

  // ==========================================
  // BILINGUAL TRANSLATION DICTIONARY
  // ==========================================
  const translations = {
    en: {
      // Nav
      'nav.home': 'Home',
      'nav.whyZipoo': 'Why Zipoo',
      'nav.features': 'What It Helps With',
      'nav.status': 'Availability',
      'nav.founding': 'Founding 20',
      'nav.pricing': 'Pricing',
      'nav.faq': 'FAQ',
      'nav.login': 'Login',
      'nav.cta': 'Join Founding 20',

      // Hero
      'hero.badge': 'FOUNDING 20 — LIMITED EARLY ACCESS',
      'hero.title': 'Run your business.<br><span class="highlight">Know your numbers.</span>',
      'hero.subtitle': 'Manage sales, stock, customers, expenses and debts from your phone or computer — simply and in one place.',
      'hero.primaryCta': 'Join the Founding 20',
      'hero.secondaryCta': 'See How Zipoo Works',
      'hero.trust1': 'Built for Tanzania 🇹🇿',
      'hero.trust2': 'Personal onboarding & setup',
      'hero.trust3': 'Special founder pricing',

      // Demo Mockup
      'mockup.badge': 'Sample Zipoo Dashboard',
      'mockup.location': 'Demo Business • Retail Store',
      'mockup.todaySales': "Today's Sales",
      'mockup.expenses': 'Expenses',
      'mockup.credit': 'Outstanding Credit',
      'mockup.lowStock': 'Low Stock',
      'mockup.lowStockVal': '8 Products',
      'mockup.streamTitle': 'Sample POS Sales Stream',
      'mockup.cash': 'Cash',
      'mockup.mpesa': 'Lipa kwa Simu',

      // Problem Section
      'prob.kicker': 'The Reality of Running a Shop',
      'prob.title': "Running a business shouldn't mean guessing.",
      'prob.sub': "Every day, business owners face questions that shouldn't require digging through notebooks.",
      'prob.q1': 'How much did I sell today?',
      'prob.q2': 'Which products are running low?',
      'prob.q3': 'Who owes me money?',
      'prob.q4': 'How much did I spend today?',
      'prob.q5': 'Is my business actually making money?',
      'prob.resolution.title': 'Zipoo brings these answers together in one place.',
      'prob.resolution.sub': 'No more scattered notebooks or evening math headaches. See your numbers clearly from your phone or computer at any time.',

      // What Zipoo Helps With (6 Core Areas)
      'feat.kicker': 'Core Capabilities',
      'feat.title': 'What Zipoo Helps With',
      'feat.subtitle': 'Six simple tools to manage daily operations with clarity — without complicated ERP jargon.',
      'feat.1.title': 'Sales',
      'feat.1.desc': 'Record sales and understand how your business is performing each day.',
      'feat.2.title': 'Stock',
      'feat.2.desc': 'Know what you have, what is selling fast and what is running low.',
      'feat.3.title': 'Customers',
      'feat.3.desc': 'Keep customer information and purchasing activity organized in one directory.',
      'feat.4.title': 'Credit & Debts',
      'feat.4.desc': 'Track customers who owe you money, record repayments, and never lose credit records.',
      'feat.5.title': 'Expenses',
      'feat.5.desc': 'Record business expenses and understand where your hard-earned money goes.',
      'feat.6.title': 'Reports',
      'feat.6.desc': 'See important daily, weekly, and monthly numbers without calculating everything manually.',

      // Available vs Coming Soon
      'status.kicker': 'Transparent Development',
      'status.title': "What's Available vs. What's Coming to Zipoo",
      'status.subtitle': 'We believe in 100% honesty: we only promise what works today, while developing future features together with our Founding 20.',
      'status.avail.title': 'Available During Early Access',
      'status.avail.pill': 'Functional Today',
      'status.avail.1': 'Recording daily sales & counter checkout',
      'status.avail.2': 'Product catalog & price management',
      'status.avail.3': 'Customer profiles & contact records',
      'status.avail.4': 'Basic stock quantity tracking',
      'status.avail.5': 'Shop expenses & spending entries',
      'status.avail.6': 'Basic dashboard & financial summaries',
      'status.avail.7': 'Full English and Kiswahili toggle',
      'status.coming.title': 'Coming to Zipoo',
      'status.coming.pill': 'Being Developed During Early Access',
      'status.coming.1': 'Advanced offline mode with background sync',
      'status.coming.2': 'Automatic multi-device synchronization',
      'status.coming.3': 'Multi-branch & multi-shop management',
      'status.coming.4': 'WhatsApp debt payment reminders',
      'status.coming.5': 'SMS receipt notifications',
      'status.coming.6': 'Advanced downloadable reports (PDF & Excel)',
      'status.coming.7': 'Barcode printing & custom labels',
      'status.coming.8': 'Granular staff & cashier permissions',
      'status.coming.9': 'Inter-branch stock transfers',
      'status.coming.10': 'Detailed net profit margin analytics',

      // Target Businesses
      'target.kicker': 'Target Audience',
      'target.title': 'Built for Businesses Managing Stock, Customers & Sales',
      'target.subtitle': 'Initially focusing on businesses with inventory and counter operations in Tanzania.',
      'target.1': 'Retail Shops & Mini-Markets',
      'target.2': 'Hardware & Building Materials',
      'target.3': 'Wholesalers & Distributors',
      'target.4': 'Auto Spare-Parts & Garages',
      'target.5': 'Electronics & Mobile Phone Shops',
      'target.6': 'Fashion, Boutiques & Cosmetics',
      'target.7': 'Small FMCG Distributors',
      'target.8': 'Other Businesses Managing Stock & Sales',

      // Founding 20 Campaign
      'f20.kicker': 'Exclusive Early Programme',
      'f20.title': 'Join the Zipoo Founding 20',
      'f20.copy': 'We are looking for 20 Tanzanian businesses to become the first businesses using Zipoo. These businesses will help us test the system in real working environments and shape the features that matter most.',
      'f20.b1': 'Personal onboarding & training',
      'f20.b2': 'Hands-on help setting up your business',
      'f20.b3': 'Help importing initial products where practical',
      'f20.b4': 'Direct WhatsApp line to the Zipoo founding team',
      'f20.b5': 'Priority support with rapid turnaround',
      'f20.b6': 'Early access to test all new features',
      'f20.b7': 'Opportunity to directly influence future roadmap',
      'f20.b8': 'Special founder pricing locked for your first year',
      'f20.cta': 'Apply for Founding 20',

      // Founding Structure
      'tier.1.badge': 'First 5 Businesses',
      'tier.1.title': 'Design Partners',
      'tier.1.desc': 'Selected businesses will use Zipoo during the pilot period and work closely with us to improve the platform.',
      'tier.1.price': 'Free',
      'tier.1.period': 'during initial pilot',
      'tier.1.b1': 'Zero subscription fee during pilot',
      'tier.1.b2': 'Direct 1-on-1 team support',
      'tier.1.b3': 'Personal on-site / remote business setup',
      'tier.1.b4': 'Regular feedback & feature-request sessions',
      'tier.2.badge': 'Businesses 6–20',
      'tier.2.title': 'Founding Customers',
      'tier.2.desc': 'The remaining 15 early businesses get full access, onboarding support, and grandfathered founder pricing.',
      'tier.2.price': 'TZS 15,000',
      'tier.2.period': '/ month',
      'tier.2.alt': 'or TZS 150,000 / year',
      'tier.2.note': 'Special founder pricing applies during your first year.',
      'tier.2.b1': 'Complete POS & business management',
      'tier.2.b2': 'Full onboarding and product import assistance',
      'tier.2.b3': 'Priority customer support',
      'tier.2.b4': 'Direct voice in upcoming feature releases',

      // Application Form
      'form.kicker': 'Apply Now',
      'form.title': 'Founding 20 Application Form',
      'form.subtitle': 'Tell us about your business. We will personally review every application and contact you within 24 hours.',
      'form.fullName': 'Full Name',
      'form.phone': 'Phone / WhatsApp Number',
      'form.bizName': 'Business Name',
      'form.bizType': 'Business Type',
      'form.bizTypeSelect': 'Select your business type',
      'form.typeRetail': 'Retail Shop / Mini-Market',
      'form.typeHardware': 'Hardware & Building Materials',
      'form.typeWholesale': 'Wholesale Store',
      'form.typeSpare': 'Auto Spare-Parts',
      'form.typeElectronics': 'Electronics / Phone Accessories',
      'form.typeFashion': 'Fashion / Boutique / Cosmetics',
      'form.typeDistributor': 'Small Distributor',
      'form.typeOther': 'Other business',
      'form.region': 'Region (Mkoa)',
      'form.regionPlaceholder': 'e.g. Dar es Salaam, Arusha, Mwanza...',
      'form.district': 'District / Area (Wilaya au Eneo)',
      'form.districtPlaceholder': 'e.g. Kariakoo, Ilala, Kinondoni...',
      'form.salesMethod': 'How do you currently record sales?',
      'form.salesNotebook': 'Notebook (Daftari)',
      'form.salesExcel': 'Excel / Spreadsheet',
      'form.salesPos': 'Another POS system',
      'form.salesApp': 'Another mobile app',
      'form.salesNothing': 'Nothing / Memorized',
      'form.numProducts': 'Approximate number of products',
      'form.prodUnder100': 'Under 100',
      'form.prod100to500': '100 – 500',
      'form.prod500to2k': '500 – 2,000',
      'form.prodOver2k': 'More than 2,000',
      'form.numStaff': 'Number of staff',
      'form.staff1to2': '1 – 2 staff',
      'form.staff3to5': '3 – 5 staff',
      'form.staff6plus': '6 or more staff',
      'form.numBranches': 'Number of shops / branches',
      'form.branch1': '1 branch (single shop)',
      'form.branch2to3': '2 – 3 branches',
      'form.branch4plus': '4+ branches',
      'form.biggestProblem': 'What is your biggest business-management problem?',
      'form.probStock': 'Stock & missing items',
      'form.probSales': 'Sales tracking & cash',
      'form.probDebts': 'Customer debts (Madeni)',
      'form.probExpenses': 'Shop expenses',
      'form.probStaff': 'Staff & cashier management',
      'form.probReports': 'Knowing real profits / reports',
      'form.probOther': 'Other challenges',
      'form.notes': 'Tell us more about your business (Optional)',
      'form.notesPlaceholder': 'Describe your main products, daily workflow, or what you hope Zipoo helps you solve...',
      'form.submit': 'Apply for Early Access',
      'form.submitting': 'Submitting application...',
      'form.successTitle': 'Thank You! Application Received',
      'form.successText': 'Thank you. We will contact you about joining the Zipoo Founding 20. Our team personally reviews each submission and will reach out via WhatsApp or phone call.',
      'form.whatsappChat': 'Chat Directly with Founding Team on WhatsApp',

      // Device Section
      'plat.kicker': 'Accessibility',
      'plat.title': 'One business. Every device.',
      'plat.subtitle': 'Use Zipoo from your phone while working in the shop and check your business from your computer when you need a bigger view.',
      'plat.phone.title': 'On Your Smartphone',
      'plat.phone.desc': 'Record sales on the shop floor, check stock levels, and see daily numbers wherever you are.',
      'plat.pc.title': 'On Your PC & Laptop',
      'plat.pc.desc': 'Enjoy a wider counter experience with keyboard shortcuts, faster data entry, and full-screen tables.',
      'plat.box.title': 'Zero Expensive Hardware Required',
      'plat.box.sub': 'Access Zipoo seamlessly from standard devices you already have:',
      'plat.box.1': 'Android smartphones & tablets',
      'plat.box.2': 'iPhones & iPads',
      'plat.box.3': 'Windows laptops & desktop PCs',
      'plat.box.4': 'Standard Bluetooth & USB thermal receipt printers',

      // Tanzania Positioning
      'tz.kicker': 'Local Context',
      'tz.title': 'Built for businesses in Tanzania 🇹🇿',
      'tz.subtitle': 'Tailored to the way retail and wholesale stores actually operate in our local economy.',
      'tz.1.title': 'Native TZS Currency',
      'tz.1.desc': 'All accounting, balances, and reports designed around Tanzanian Shillings without conversion issues.',
      'tz.2.title': 'English & Kiswahili',
      'tz.2.desc': 'Full bilingual support ensures you and your cashiers can work in the language you are most comfortable with.',
      'tz.3.title': 'Tanzanian Business Workflows',
      'tz.3.desc': 'Built around customer credit (madeni), mobile money (Lipa kwa Simu), and daily drawer handovers.',
      'tz.4.title': 'Phone-First Simplicity',
      'tz.4.desc': 'Optimized for mobile screens and light data consumption on standard mobile internet bundles.',
      'tz.5.title': 'Local Dar es Salaam Support',
      'tz.5.desc': 'Direct assistance from a team on the ground that understands your local trading environment.',

      // Pricing Section
      'price.kicker': 'Early Access Pricing',
      'price.title': 'Transparent Founding Pricing',
      'price.subtitle': 'Special first-year rates for the 20 businesses helping us test and build Zipoo.',
      'price.1.badge': 'First 5 Businesses',
      'price.1.title': 'Design Partner',
      'price.1.price': 'Free',
      'price.1.period': 'during pilot',
      'price.1.desc': 'Work closely with our product team to test workflows and suggest essential improvements.',
      'price.1.b1': 'Full access during pilot period',
      'price.1.b2': 'Free personal onboarding & setup',
      'price.1.b3': 'Weekly check-in & feedback sessions',
      'price.1.cta': 'Apply as Design Partner',
      'price.2.badge': 'Businesses 6–20',
      'price.2.title': 'Founding Business',
      'price.2.price': 'TZS 15,000',
      'price.2.period': '/ month',
      'price.2.alt': 'or TZS 150,000 / year',
      'price.2.desc': 'Early adopter rate locked in for your entire first year of using Zipoo.',
      'price.2.b1': 'Complete core POS & stock tools',
      'price.2.b2': 'Help setting up & importing stock',
      'price.2.b3': 'Priority direct team support',
      'price.2.b4': 'Early access to all new updates',
      'price.2.cta': 'Apply for Founding 20',
      'price.3.badge': 'Coming After Early Access',
      'price.3.title': 'Standard Zipoo',
      'price.3.price': 'From TZS 25,000',
      'price.3.period': '/ month',
      'price.3.desc': 'Standard commercial pricing when Zipoo opens for general public registration.',
      'price.3.b1': 'General public subscription',
      'price.3.b2': 'Self-serve setup & guides',
      'price.3.b3': 'Standard email/chat support',
      'price.3.cta': 'Coming Soon',

      // Social Proof
      'proof.title': "We're starting with our first 20 businesses.",
      'proof.sub': 'Real businesses in Kariakoo and across Tanzania testing every workflow in real shop environments.',

      // FAQ
      'faq.kicker': 'Common Questions',
      'faq.title': 'Frequently Asked Questions',
      'faq.subtitle': 'Everything you need to know about the Zipoo Early Access and Founding 20 programme.',
      'faq.q1': 'Is Zipoo ready to use?',
      'faq.a1': 'Zipoo is currently in Early Access. Core features like sales recording, products, customers, expenses, and basic stock are functional today, while advanced features are being actively developed together with our first businesses.',
      'faq.q2': 'Who can join the Founding 20?',
      'faq.a2': 'We are initially looking for Tanzanian businesses that manage stock, counter sales, customers, or credit — such as retail shops, hardware stores, wholesalers, auto-part shops, and electronics boutiques.',
      'faq.q3': 'Do I need a computer?',
      'faq.a3': 'No! Zipoo works directly on supported smartphones, tablets, laptops, and desktop computers through modern web browsers. You do not need to buy expensive computer equipment.',
      'faq.q4': 'Is Zipoo available in Kiswahili?',
      'faq.a4': 'Yes! Zipoo is fully bilingual and supports both English and Kiswahili across all user interfaces.',
      'faq.q5': 'Will my feedback matter?',
      'faq.a5': 'Yes, absolutely. The entire purpose of the Founding 20 is to build Zipoo around real needs. Founding businesses have a direct communication channel to the creators to suggest features and refine workflows.',
      'faq.q6': 'How much does it cost?',
      'faq.a6': 'The first 5 selected design partners use Zipoo completely free during the initial pilot. Founding businesses 6–20 receive a special first-year founder rate of TZS 15,000/month or TZS 150,000/year.',

      // Final CTA
      'cta.title': 'Help us build Zipoo around real businesses.',
      'cta.subtitle': 'Join the first 20 businesses using Zipoo and help shape a business platform built for Tanzania.',
      'cta.primary': 'Apply for Founding 20',
      'cta.secondary': 'Explore What Zipoo Helps With',

      // Footer
      'footer.tagline': 'A modern business management platform being built with real Tanzanian businesses.',
      'footer.co': 'Company',
      'footer.about': 'About Zipoo',
      'footer.pricing': 'Early Pricing',
      'footer.login': 'Sign In',
      'footer.contact': 'Contact & Support',
      'footer.location': 'Dar es Salaam, Tanzania',
      'footer.rights': 'All rights reserved. Run your business. Know your numbers.',
    },

    sw: {
      // Nav
      'nav.home': 'Mwanzo',
      'nav.whyZipoo': 'Kwanini Zipoo',
      'nav.features': 'Inachosaidia',
      'nav.status': 'Upatikanaji',
      'nav.founding': 'Biashara 20',
      'nav.pricing': 'Bei',
      'nav.faq': 'Maswali',
      'nav.login': 'Ingia',
      'nav.cta': 'Jiunge na Biashara 20',

      // Hero
      'hero.badge': 'BIASHARA 20 ZA KWANZA — NAFASI CHACHE',
      'hero.title': 'Simamia biashara yako.<br><span class="highlight">Zijue namba zako.</span>',
      'hero.subtitle': 'Simamia mauzo, stock, wateja, matumizi na madeni kupitia simu au kompyuta yako — kwa urahisi, sehemu moja.',
      'hero.primaryCta': 'Jiunge na Biashara 20 za Kwanza',
      'hero.secondaryCta': 'Ona Jinsi Inavyofanya Kazi',
      'hero.trust1': 'Imetengenezwa Tanzania 🇹🇿',
      'hero.trust2': 'Usaidizi binafsi wa kuanza',
      'hero.trust3': 'Bei maalum ya waasisi',

      // Demo Mockup
      'mockup.badge': 'Mfano wa Dashibodi ya Zipoo',
      'mockup.location': 'Biashara ya Mfano • Duka la Reja Reja',
      'mockup.todaySales': 'Mauzo ya Leo',
      'mockup.expenses': 'Matumizi',
      'mockup.credit': 'Madeni ya Wateja',
      'mockup.lowStock': 'Bidhaa Zinazoisha',
      'mockup.lowStockVal': 'Bidhaa 8',
      'mockup.streamTitle': 'Mfano wa Mauzo ya Kaunta (POS)',
      'mockup.cash': 'Taslimu (Cash)',
      'mockup.mpesa': 'Lipa kwa Simu',

      // Problem Section
      'prob.kicker': 'Uhalisia wa Kuendesha Duka',
      'prob.title': 'Kuendesha biashara hakupaswi kuwa kubahatisha.',
      'prob.sub': 'Kila siku, wamiliki wa biashara wanakutana na maswali ambayo hayakupaswi kuhitaji kupekuwa madaftari.',
      'prob.q1': 'Leo nimeuza kiasi gani?',
      'prob.q2': 'Ni bidhaa gani zinakaribia kuisha?',
      'prob.q3': 'Ni wateja gani wanadaiwa?',
      'prob.q4': 'Leo nimetumia kiasi gani?',
      'prob.q5': 'Biashara yangu kweli inatengeneza faida?',
      'prob.resolution.title': 'Zipoo inakusanya majibu haya yote sehemu moja.',
      'prob.resolution.sub': 'Acha kuandika kwenye madaftari yaliyotawanyika au kupiga hesabu ndefu jioni. Zijue namba zako wakati wowote kupitia simu au kompyuta yako.',

      // What Zipoo Helps With (6 Core Areas)
      'feat.kicker': 'Uwezo wa Mfumo',
      'feat.title': 'Kile Zipoo Inachokusaidia',
      'feat.subtitle': 'Mambo 6 ya msingi ya kuendesha biashara yako kwa uwazi kila siku — bila maneno magumu ya mifumo.',
      'feat.1.title': 'Mauzo',
      'feat.1.desc': 'Rekodi mauzo na uelewe mwenendo wa biashara yako kila siku.',
      'feat.2.title': 'Stock / Stoo',
      'feat.2.desc': 'Jua bidhaa ulizonazo, zinazouza zaidi na zinazokaribia kuisha stoo.',
      'feat.3.title': 'Wateja',
      'feat.3.desc': 'Weka taarifa za wateja na mawasiliano yao kwa mpangilio mzuri sehemu moja.',
      'feat.4.title': 'Madeni ya Wateja',
      'feat.4.desc': 'Fuatilia wateja wanaokudaiwa, rekodi malipo ya awamu, na usipoteze tena kumbukumbu.',
      'feat.5.title': 'Matumizi',
      'feat.5.desc': 'Rekodi matumizi ya biashara na ujue wapi pesa zako zinakwenda.',
      'feat.6.title': 'Ripoti',
      'feat.6.desc': 'Ona namba zako muhimu bila kupiga hesabu ndefu kwa mkono.',

      // Available vs Coming Soon
      'status.kicker': 'Uwazi Katika Ujenzi wa Mfumo',
      'status.title': 'Kile Kilichopo Sasa vs. Kinachokuja Zipoo',
      'status.subtitle': 'Tunaamini katika uwazi wa 100%: tunaahidi tu kile kinachofanya kazi leo, huku tukijenga vipengele vingine pamoja na biashara 20 za kwanza.',
      'status.avail.title': 'Inapatikana Wakati wa Early Access',
      'status.avail.pill': 'Inafanya Kazi Sasa',
      'status.avail.1': 'Kurekodi mauzo ya kila siku na kuuza kaunta',
      'status.avail.2': 'Orodha ya bidhaa na bei zake',
      'status.avail.3': 'Kuhifadhi majina na namba za wateja',
      'status.avail.4': 'Ufuatiliaji wa idadi ya bidhaa zilizobaki stoo',
      'status.avail.5': 'Kurekodi matumizi madogo ya duka',
      'status.avail.6': 'Dashibodi ya muhtasari wa mauzo na pesa',
      'status.avail.7': 'Kubadili lugha ya Kiingereza na Kiswahili',
      'status.coming.title': 'Kinachokuja Zipoo',
      'status.coming.pill': 'Kinajengwa Wakati wa Early Access',
      'status.coming.1': 'Kufanya kazi bila intaneti na kujisawazisha mtandao ukirudi',
      'status.coming.2': 'Kujisawazisha kiotomatiki kati ya vifaa vingi',
      'status.coming.3': 'Usimamizi wa matawi na maduka mengi',
      'status.coming.4': 'Ukumbusho wa madeni kwa wateja kupitia WhatsApp',
      'status.coming.5': 'Kutuma risiti kwa ujumbe wa SMS',
      'status.coming.6': 'Kupakua ripoti za kina za PDF na Excel',
      'status.coming.7': 'Kuchapisha barcode za bidhaa',
      'status.coming.8': 'Ruhusa tofauti za wafanyakazi na wauzaji',
      'status.coming.9': 'Uhamisho wa stoo kati ya maduka',
      'status.coming.10': 'Uchambuzi wa kina wa faida halisi baada ya gharama zote',

      // Target Businesses
      'target.kicker': 'Biashara Lengwa',
      'target.title': 'Imejengwa kwa Biashara Zinazosimamia Bidhaa, Wateja na Mauzo',
      'target.subtitle': 'Hapo mwanzo inalenga maduka na biashara zinazouza bidhaa nchini Tanzania.',
      'target.1': 'Maduka ya Reja Reja & Mini-Supermarkets',
      'target.2': 'Maduka ya Vifaa vya Ujenzi (Hardware)',
      'target.3': 'Wafanyabiashara wa Jumla & Wasambazaji',
      'target.4': 'Maduka ya Spea za Magari & Pikipiki',
      'target.5': 'Maduka ya Vifaa vya Umeme & Simu',
      'target.6': 'Maduka ya Nguo, Viatu & Vipodozi',
      'target.7': 'Wasambazaji Wadogo wa Bidhaa',
      'target.8': 'Biashara Nyingine zenye Stoo na Mauzo',

      // Founding 20 Campaign
      'f20.kicker': 'Nafasi Maalum ya Waasisi',
      'f20.title': 'Jiunge na Biashara 20 za Kwanza za Zipoo',
      'f20.copy': 'Tunatafuta biashara 20 za Kitanzania kuwa biashara za kwanza kutumia Zipoo. Biashara hizi zitatusaidia kufanyia majaribio mfumo huu katika mazingira halisi ya kazi na kutengeneza vipengele vyenye umuhimu mkubwa zaidi.',
      'f20.b1': 'Mafunzo na usaidizi binafsi wa ana kwa ana',
      'f20.b2': 'Kusaidiwa kuweka mfumo kwenye biashara yako',
      'f20.b3': 'Msaada wa kuingiza orodha ya bidhaa zako stoo',
      'f20.b4': 'Mawasiliano ya moja kwa moja ya WhatsApp na timu ya Zipoo',
      'f20.b5': 'Kupewa kipaumbele kwenye huduma na majibu ya haraka',
      'f20.b6': 'Kutumia vipengele vipya kabla ya wengine wote',
      'f20.b7': 'Uwezo wa kupendekeza vipengele unavyovihitaji',
      'f20.b8': 'Bei maalum ya waasisi iliyopunguzwa kwa mwaka mzima',
      'f20.cta': 'Omba Nafasi ya Biashara 20 za Kwanza',

      // Founding Structure
      'tier.1.badge': 'Biashara 5 za Kwanza',
      'tier.1.title': 'Washirika wa Ujenzi (Design Partners)',
      'tier.1.desc': 'Biashara 5 teule zitakazotumia Zipoo wakati wa majaribio ya kwanza na kufanya kazi nasi kwa ukaribu kuboresha mfumo.',
      'tier.1.price': 'Bure',
      'tier.1.period': 'wakati wa majaribio',
      'tier.1.b1': 'Hutolipa gharama yoyote ya mwezi wakati wa majaribio',
      'tier.1.b2': 'Usaidizi wa moja kwa moja kutoka kwa wataalamu',
      'tier.1.b3': 'Kusaidiwa kuweka biashara yako kwenye mfumo',
      'tier.1.b4': 'Kukutana na kutoa maoni ya vipengele unavyotaka',
      'tier.2.badge': 'Biashara 6–20',
      'tier.2.title': 'Wateja Waasisi (Founding Customers)',
      'tier.2.desc': 'Biashara 15 zitakazofuata zitapata huduma kamili, usaidizi wa kuingiza bidhaa, na bei maalum iliyopunguzwa.',
      'tier.2.price': 'TZS 15,000',
      'tier.2.period': '/ mwezi',
      'tier.2.alt': 'au TZS 150,000 / mwaka',
      'tier.2.note': 'Bei hii maalum ya waasisi inatumika kwa mwaka wa kwanza mzima.',
      'tier.2.b1': 'Kutumia mfumo kamili wa kuuza na stoo',
      'tier.2.b2': 'Kusaidiwa kuweka na kuingiza bidhaa zako',
      'tier.2.b3': 'Huduma ya haraka na kipaumbele cha pekee',
      'tier.2.b4': 'Kupata vipengele vipya kabla ya wengine',

      // Application Form
      'form.kicker': 'Omba Sasa',
      'form.title': 'Fomu ya Maombi ya Biashara 20 za Kwanza',
      'form.subtitle': 'Tueleze machache kuhusu biashara yako. Tutapitia kila ombi binafsi na kuwasiliana nawe ndani ya saa 24.',
      'form.fullName': 'Jina Lako Kamili',
      'form.phone': 'Namba ya Simu / WhatsApp',
      'form.bizName': 'Jina la Biashara',
      'form.bizType': 'Aina ya Biashara',
      'form.bizTypeSelect': 'Chagua aina ya biashara yako',
      'form.typeRetail': 'Duka la Reja Reja / Mini-Market',
      'form.typeHardware': 'Duka la Vifaa vya Ujenzi (Hardware)',
      'form.typeWholesale': 'Duka la Jumla (Wholesale)',
      'form.typeSpare': 'Spea za Magari / Pikipiki',
      'form.typeElectronics': 'Vifaa vya Umeme / Simu',
      'form.typeFashion': 'Duka la Nguo / Viatu / Vipodozi',
      'form.typeDistributor': 'Msambazaji Mdogo',
      'form.typeOther': 'Biashara nyingine',
      'form.region': 'Mkoa',
      'form.regionPlaceholder': 'mf. Dar es Salaam, Arusha, Mwanza...',
      'form.district': 'Wilaya au Eneo la Biashara',
      'form.districtPlaceholder': 'mf. Kariakoo, Ilala, Kinondoni...',
      'form.salesMethod': 'Kwa sasa unarekodi vipi mauzo yako?',
      'form.salesNotebook': 'Daftari la mkono',
      'form.salesExcel': 'Excel / Kompyuta',
      'form.salesPos': 'Mfumo mwingine wa POS',
      'form.salesApp': 'App nyingine ya simu',
      'form.salesNothing': 'Hakuna / Nakariri kichwani',
      'form.numProducts': 'Makadirio ya idadi ya bidhaa zako',
      'form.prodUnder100': 'Chini ya 100',
      'form.prod100to500': 'Bidhaa 100 – 500',
      'form.prod500to2k': 'Bidhaa 500 – 2,000',
      'form.prodOver2k': 'Zaidi ya 2,000',
      'form.numStaff': 'Idadi ya wafanyakazi / wauzaji',
      'form.staff1to2': 'Wafanyakazi 1 – 2',
      'form.staff3to5': 'Wafanyakazi 3 – 5',
      'form.staff6plus': 'Wafanyakazi 6 au zaidi',
      'form.numBranches': 'Idadi ya maduka / matawi',
      'form.branch1': 'Tawi 1 (duka moja)',
      'form.branch2to3': 'Matawi 2 – 3',
      'form.branch4plus': 'Matawi 4 au zaidi',
      'form.biggestProblem': 'Ni changamoto gani kubwa ya kiutawala inayokusumbua?',
      'form.probStock': 'Stoo na bidhaa kupotea',
      'form.probSales': 'Kufuatilia mauzo na pesa',
      'form.probDebts': 'Madeni ya wateja (Kusahau madeni)',
      'form.probExpenses': 'Kudhibiti matumizi ya duka',
      'form.probStaff': 'Usimamizi wa wafanyakazi na wauzaji',
      'form.probReports': 'Kutojua faida halisi / ripoti',
      'form.probOther': 'Changamoto nyingine',
      'form.notes': 'Tueleze machache zaidi kuhusu biashara yako (Hiyari)',
      'form.notesPlaceholder': 'Eleza bidhaa unazouza au changamoto unazotaka Zipoo ikusaidie kutatua...',
      'form.submit': 'Omba Kushiriki',
      'form.submitting': 'Inatuma maombi yako...',
      'form.successTitle': 'Asante! Maombi Yako Yamepokelewa',
      'form.successText': 'Asante. Tutawasiliana nawe kuhusu nafasi ya kujiunga na biashara 20 za kwanza za Zipoo. Timu yetu itapitia taarifa zako na kukupigia au kukutumia ujumbe kwa WhatsApp.',
      'form.whatsappChat': 'Wasiliana Moja kwa Moja na Waasisi WhatsApp',

      // Device Section
      'plat.kicker': 'Urahisi wa Matumizi',
      'plat.title': 'Biashara moja. Kwenye vifaa vyako vyote.',
      'plat.subtitle': 'Tumia Zipoo kwenye simu yako ukiwa dukani na angalia mwenendo wa biashara kwenye kompyuta unapohitaji muonekano mpana zaidi.',
      'plat.phone.title': 'Kwenye Simu Yako ya Mkononi',
      'plat.phone.desc': 'Rekodi mauzo kaunta, angalia idadi ya bidhaa stoo, na fuatilia namba zako popote ulipo.',
      'plat.pc.title': 'Kwenye Kompyuta & Laptop',
      'plat.pc.desc': 'Pata muonekano mpana zaidi wa kaunta wenye njia za mkato za keyboard na jedwali kubwa za bidhaa.',
      'plat.box.title': 'Hauhitaji Vifaa vya Gharama Kubwa',
      'plat.box.sub': 'Inafanya kazi kwenye vifaa vya kawaida unavyomiliki tayari:',
      'plat.box.1': 'Simu na tablet za Android',
      'plat.box.2': 'iPhone na iPad za Apple',
      'plat.box.3': 'Laptop na kompyuta za Windows / Mac',
      'plat.box.4': 'Printa za kawaida za risiti za Bluetooth & USB',

      // Tanzania Positioning
      'tz.kicker': 'Mazingira ya Nyumbani',
      'tz.title': 'Imetengenezwa kwa biashara za Tanzania 🇹🇿',
      'tz.subtitle': 'Inaendana na jinsi maduka ya reja reja na ya jumla yanavyoendeshwa katika mazingira yetu ya Kitanzania.',
      'tz.1.title': 'Sarafu Halisi ya TZS',
      'tz.1.desc': 'Hesabu zote, salio na ripoti zimewekwa kwa Shilingi ya Kitanzania bila masuala ya kubadili sarafu.',
      'tz.2.title': 'Kiingereza & Kiswahili',
      'tz.2.desc': 'Uwezo kamili wa lugha zote mbili unamwezesha mmiliki na muuzaji kufanya kazi kwa lugha anayoielewa vizuri.',
      'tz.3.title': 'Mifumo ya Biashara za Kitanzania',
      'tz.3.desc': 'Imejengwa ikizingatia madeni ya wateja (madeni), malipo ya mitandao (Lipa kwa Simu), na upashanaji wa droo ya pesa.',
      'tz.4.title': 'Urahisi Kwenye Simu',
      'tz.4.desc': 'Imeboreshwa kutumia bando ndogo ya intaneti na kufanya kazi vizuri kwenye simu za kawaida.',
      'tz.5.title': 'Usaidizi Hapa Hapa Nchini',
      'tz.5.desc': 'Msaada wa haraka kutoka kwa timu iliyopo Dar es Salaam inayoelewa mazingira yako ya biashara.',

      // Pricing Section
      'price.kicker': 'Bei ya Awali (Early Access)',
      'price.title': 'Gharama Wazi za Waasisi',
      'price.subtitle': 'Bei maalum ya mwaka wa kwanza kwa biashara 20 zitakazotangulia kutumia na kuboresha Zipoo.',
      'price.1.badge': 'Biashara 5 za Kwanza',
      'price.1.title': 'Washirika wa Ujenzi',
      'price.1.price': 'Bure',
      'price.1.period': 'wakati wa majaribio',
      'price.1.desc': 'Fanya kazi kwa ukaribu na timu yetu kufanyia majaribio mfumo na kupendekeza maboresho.',
      'price.1.b1': 'Matumizi kamili wakati wa majaribio',
      'price.1.b2': 'Kusaidiwa bure kuweka biashara yako',
      'price.1.b3': 'Mawasiliano na vikao vya maoni kila wiki',
      'price.1.cta': 'Omba Nafasi ya Ushirika',
      'price.2.badge': 'Biashara 6–20',
      'price.2.title': 'Wateja Waasisi',
      'price.2.price': 'TZS 15,000',
      'price.2.period': '/ mwezi',
      'price.2.alt': 'au TZS 150,000 / mwaka',
      'price.2.desc': 'Bei maalum iliyopunguzwa itakayodumu kwa mwaka wako wote wa kwanza.',
      'price.2.b1': 'Mfumo mzima wa mauzo, stoo na madeni',
      'price.2.b2': 'Msaada wa kuweka na kuingiza bidhaa',
      'price.2.b3': 'Kipaumbele cha huduma kwa wateja',
      'price.2.b4': 'Kupata vipengele vipya mapema',
      'price.2.cta': 'Jiunge na Biashara 20',
      'price.3.badge': 'Baada ya Early Access',
      'price.3.title': 'Zipoo ya Kawaida',
      'price.3.price': 'Kuanzia TZS 25,000',
      'price.3.period': '/ mwezi',
      'price.3.desc': 'Bei itakayotumika pindi nafasi za waasisi zitakapofungwa na kuanza usajili wa umma.',
      'price.3.b1': 'Usajili wa kawaida wa umma',
      'price.3.b2': 'Kujiwekea mfumo mwenyewe kwa maelekezo',
      'price.3.b3': 'Huduma ya kawaida ya mteja',
      'price.3.cta': 'Inakuja Hivi Karibuni',

      // Social Proof
      'proof.title': 'Tunaanza na biashara zetu 20 za kwanza.',
      'proof.sub': 'Tukifanya kazi kwa ukaribu na wamiliki wa maduka Kariakoo na kote Tanzania katika mazingira halisi ya kazi.',

      // FAQ
      'faq.kicker': 'Maswali ya Kawaida',
      'faq.title': 'Maswali Yanayoulizwa Mara kwa Mara',
      'faq.subtitle': 'Kila unachopaswa kujua kuhusu hatua ya Early Access na mpango wa Biashara 20 za Kwanza za Zipoo.',
      'faq.q1': 'Je, Zipoo iko tayari kutumika sasa?',
      'faq.a1': 'Zipoo kwa sasa iko katika hatua ya majaribio ya awali (Early Access). Vipengele vya msingi kama kurekodi mauzo, kuweka bidhaa, wateja, matumizi na stoo ya msingi vinafanya kazi leo, huku vipengele vya ziada vikijengwa kwa ushirikiano na biashara zetu za kwanza.',
      'faq.q2': 'Nani anayeweza kujiunga na Biashara 20 za Kwanza?',
      'faq.a2': 'Hapo awali tunatafuta biashara za Kitanzania zinazouza bidhaa, zinazosimamia stoo au zinazodai wateja — kama vile maduka ya reja reja, vifaa vya ujenzi (hardware), wauzaji wa jumla, spea za magari, na maduka ya vifaa vya umeme/simu.',
      'faq.q3': 'Je, ninahitaji kompyuta kuitumia?',
      'faq.a3': 'Hapana! Zipoo inafanya kazi moja kwa moja kwenye simu za mkononi, tablet, laptop na kompyuta za kawaida kupitia kivinjari. Hauhitaji kununua vifaa vya gharama kubwa vya kompyuta.',
      'faq.q4': 'Je, Zipoo inapatikana kwa Kiswahili?',
      'faq.a4': 'Ndio! Zipoo inasaidia lugha zote mbili: Kiswahili na Kiingereza kwenye sehemu zote za mfumo.',
      'faq.q5': 'Je, maoni yangu yatasikilizwa?',
      'faq.a5': 'Ndio, kwa 100%. Lengo kuu la mpango wa Biashara 20 za Kwanza ni kujenga mfumo unaoendana na mahitaji halisi. Biashara za kwanza zitakuwa na mawasiliano ya moja kwa moja na waanzilishi wa Zipoo.',
      'faq.q6': 'Gharama zake zikoje?',
      'faq.a6': 'Biashara 5 za kwanza (Design Partners) zitatumia Zipoo bure kabisa wakati wa majaribio ya kwanza. Biashara 6 hadi 20 zitapata bei maalum ya waasisi ya TZS 15,000 kwa mwezi au TZS 150,000 kwa mwaka.',

      // Final CTA
      'cta.title': 'Tusaidie kujenga Zipoo kwa mahitaji halisi ya biashara.',
      'cta.subtitle': 'Jiunge na biashara 20 za kwanza zinazotumia Zipoo na ushiriki kutengeneza mfumo unaoendana na biashara za Tanzania.',
      'cta.primary': 'Omba Nafasi ya Biashara 20 za Kwanza',
      'cta.secondary': 'Angalia Kile Zipoo Inachosaidia',

      // Footer
      'footer.tagline': 'Mfumo wa kisasa wa usimamizi wa biashara unaojengwa kwa ushirikiano na biashara halisi za Kitanzania.',
      'footer.co': 'Kampuni',
      'footer.about': 'Kuhusu Zipoo',
      'footer.pricing': 'Gharama za Awali',
      'footer.login': 'Ingia Kwenye Akaunti',
      'footer.contact': 'Mawasiliano & Usaidizi',
      'footer.location': 'Dar es Salaam, Tanzania',
      'footer.rights': 'Haki zote zimehifadhiwa. Simamia biashara yako. Zijue namba zako.',
    }
  };

  // State
  let currentLang = localStorage.getItem('zipoo_website_lang') || 'en';
  if (!translations[currentLang]) currentLang = 'en';

  function applyLanguage(lang) {
    if (!translations[lang]) return;
    currentLang = lang;
    localStorage.setItem('zipoo_website_lang', lang);

    document.documentElement.lang = lang;

    // Update all translatable elements
    document.querySelectorAll('[data-i18n]').forEach((el) => {
      const key = el.getAttribute('data-i18n');
      if (translations[lang] && translations[lang][key] !== undefined) {
        el.innerHTML = translations[lang][key];
      }
    });

    // Update inputs / textareas with placeholder translations
    document.querySelectorAll('[data-i18n-placeholder]').forEach((el) => {
      const key = el.getAttribute('data-i18n-placeholder');
      if (translations[lang] && translations[lang][key] !== undefined) {
        el.setAttribute('placeholder', translations[lang][key]);
      }
    });

    // Update active state of language buttons
    document.querySelectorAll('[data-lang-btn]').forEach((btn) => {
      const target = btn.getAttribute('data-lang-btn');
      btn.classList.toggle('active', target === lang);
    });
  }

  // ==========================================
  // MOBILE DRAWER
  // ==========================================
  function setupMobileDrawer() {
    const toggleBtn = document.querySelector('[data-mobile-toggle]');
    const drawer = document.querySelector('[data-mobile-drawer]');
    if (!toggleBtn || !drawer) return;

    toggleBtn.addEventListener('click', () => {
      const isOpen = drawer.classList.toggle('open');
      toggleBtn.setAttribute('aria-expanded', String(isOpen));
      toggleBtn.innerHTML = isOpen
        ? `<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>`
        : `<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>`;
    });

    // Close when clicking any nav link inside drawer
    drawer.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => {
        drawer.classList.remove('open');
        toggleBtn.setAttribute('aria-expanded', 'false');
        toggleBtn.innerHTML = `<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>`;
      });
    });
  }

  // ==========================================
  // FAQ ACCORDION
  // ==========================================
  function setupFaq() {
    const faqItems = document.querySelectorAll('[data-faq-item]');
    faqItems.forEach((item) => {
      const btn = item.querySelector('.faq-question');
      if (!btn) return;
      btn.addEventListener('click', () => {
        const isActive = item.classList.contains('active');
        faqItems.forEach((other) => {
          if (other !== item) other.classList.remove('active');
        });
        item.classList.toggle('active', !isActive);
      });
    });
  }

  // ==========================================
  // STICKY HEADER SCROLL SHADOW
  // ==========================================
  function setupHeaderScroll() {
    const header = document.querySelector('.site-header');
    if (!header) return;
    window.addEventListener(
      'scroll',
      () => {
        header.classList.toggle('scrolled', window.scrollY > 20);
      },
      { passive: true }
    );
  }

  // ==========================================
  // FOUNDING 20 APPLICATION FORM HANDLER
  // ==========================================
  function setupApplicationForm() {
    const form = document.getElementById('foundingForm');
    const successCard = document.getElementById('formSuccessCard');
    if (!form || !successCard) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();

      const submitBtn = form.querySelector('button[type="submit"]');
      const originalBtnText = submitBtn ? submitBtn.innerHTML : '';

      // Collect form data
      const formData = new FormData(form);
      const data = {
        fullName: formData.get('fullName') || '',
        phone: formData.get('phone') || '',
        businessName: formData.get('businessName') || '',
        businessType: formData.get('businessType') || '',
        region: formData.get('region') || '',
        district: formData.get('district') || '',
        salesMethod: formData.get('salesMethod') || '',
        numProducts: formData.get('numProducts') || '',
        numStaff: formData.get('numStaff') || '',
        numBranches: formData.get('numBranches') || '',
        biggestProblem: formData.get('biggestProblem') || '',
        notes: formData.get('notes') || '',
        submittedAt: new Date().toISOString(),
        language: currentLang
      };

      // Basic validation
      if (!data.fullName.trim() || !data.phone.trim() || !data.businessName.trim()) {
        alert(currentLang === 'sw' ? 'Tafadhali jaza taarifa zako muhimu (Jina, Simu, na Biashara).' : 'Please fill in all required fields (Name, Phone, and Business Name).');
        return;
      }

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = translations[currentLang]['form.submitting'] || 'Submitting application...';
      }

      // Simulate submission & save locally
      setTimeout(() => {
        try {
          const existingApps = JSON.parse(localStorage.getItem('zipoo_founding_applications') || '[]');
          existingApps.push(data);
          localStorage.setItem('zipoo_founding_applications', JSON.stringify(existingApps));
        } catch (err) {
          console.warn('Storage failed:', err);
        }

        // Prepare WhatsApp link on success card
        const waMsg = encodeURIComponent(
          `Habari Zipoo! Nimeomba nafasi ya Biashara 20 za Kwanza.\n\nJina: ${data.fullName}\nBiashara: ${data.businessName} (${data.businessType})\nEneo: ${data.district}, ${data.region}\nSimu: ${data.phone}`
        );
        const waBtn = document.getElementById('whatsappDirectBtn');
        if (waBtn) {
          waBtn.href = `https://wa.me/255700000000?text=${waMsg}`;
        }

        // Hide form, show success
        form.style.display = 'none';
        successCard.style.display = 'block';
        successCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }, 600);
    });
  }

  // ==========================================
  // SMOOTH SCROLL TO FORM FOR CTA BUTTONS
  // ==========================================
  function setupCtaScroll() {
    document.querySelectorAll('.cta-apply').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        const target = document.getElementById('apply');
        if (target) {
          e.preventDefault();
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      });
    });
  }

  // ==========================================
  // INIT
  // ==========================================
  document.addEventListener('DOMContentLoaded', () => {
    // Language buttons
    document.querySelectorAll('[data-lang-btn]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const lang = btn.getAttribute('data-lang-btn');
        applyLanguage(lang);
      });
    });

    applyLanguage(currentLang);
    setupMobileDrawer();
    setupFaq();
    setupHeaderScroll();
    setupApplicationForm();
    setupCtaScroll();
  });
})();
