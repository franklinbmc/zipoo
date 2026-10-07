/**
 * Zipoo Marketing Website - Main JS
 * Bilingual (EN & SW) translation engine, mobile drawer, FAQ accordion,
 * and smooth scroll effects.
 */

(function () {
  'use strict';

  // ==========================================
  // BILINGUAL TRANSLATION DICTIONARY
  // ==========================================
  const translations = {
    en: {
      // Nav
      'nav.whyZipoo': 'Why Zipoo',
      'nav.features': 'What It Helps With',
      'nav.devices': 'Mobile & PC',
      'nav.status': 'Capabilities',
      'nav.pricing': 'Pricing',
      'nav.faq': 'FAQ',
      'nav.login': 'Login',
      'nav.startFree': 'Start Free',

      // Hero
      'hero.title': 'Run your business.<br><span class="highlight">Know your numbers.</span>',
      'hero.subtitle': 'Manage sales, stock, customers, expenses and debts from your phone or computer — simply and in one place.',
      'hero.primaryCta': 'Start Free Today',
      'hero.secondaryCta': 'See How It Works',
      'hero.trust1': 'Built for Tanzania 🇹🇿',
      'hero.trust2': 'No credit card required',
      'hero.trust3': 'Works on phones & computers',

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
      'feat.1.desc': 'Record daily sales, track payment types, and understand how your shop is performing.',
      'feat.2.title': 'Stock',
      'feat.2.desc': 'Know what you have, what is selling fast, and get notified before products run out.',
      'feat.3.title': 'Customers',
      'feat.3.desc': 'Keep customer profiles, contact numbers, and purchasing history organized in one place.',
      'feat.4.title': 'Credit & Debts',
      'feat.4.desc': 'Track customers who owe you money, record repayments, and never lose credit records.',
      'feat.5.title': 'Expenses',
      'feat.5.desc': 'Record daily business expenses and understand where your hard-earned cash goes.',
      'feat.6.title': 'Reports',
      'feat.6.desc': 'See important daily, weekly, and monthly numbers without calculating everything manually.',

      // Available vs Coming Soon
      'status.kicker': 'Transparent Product Roadmap',
      'status.title': "What's Available Today vs. What's Coming",
      'status.subtitle': 'We believe in 100% honesty: here is what is working right now and what is currently being built.',
      'status.avail.title': 'Available Right Now',
      'status.avail.pill': 'Functional Today',
      'status.avail.1': 'Recording daily sales & fast counter checkout',
      'status.avail.2': 'Product catalog & price management',
      'status.avail.3': 'Customer profiles & contact records',
      'status.avail.4': 'Basic stock quantity tracking & alerts',
      'status.avail.5': 'Shop expenses & petty cash entries',
      'status.avail.6': 'Daily overview dashboard & summaries',
      'status.avail.7': 'Full English and Kiswahili toggle',
      'status.coming.title': 'Coming to Zipoo',
      'status.coming.pill': 'In Active Development',
      'status.coming.1': 'Advanced offline mode with background sync',
      'status.coming.2': 'Automatic multi-device synchronization',
      'status.coming.3': 'Multi-branch & warehouse management',
      'status.coming.4': 'WhatsApp debt payment reminders',
      'status.coming.5': 'SMS receipt notifications',
      'status.coming.6': 'Advanced downloadable reports (PDF & Excel)',
      'status.coming.7': 'Barcode printing & custom labels',
      'status.coming.8': 'Granular staff & cashier permissions',
      'status.coming.9': 'Inter-branch stock transfers',
      'status.coming.10': 'Detailed net profit margin analytics',

      // Target Businesses
      'target.kicker': 'Who Uses Zipoo',
      'target.title': 'Built for Businesses Managing Stock, Customers & Sales',
      'target.subtitle': 'Engineered specifically for retail and wholesale operations in Tanzania.',
      'target.1': 'Retail Shops & Mini-Markets',
      'target.2': 'Hardware & Building Materials',
      'target.3': 'Wholesalers & Distributors',
      'target.4': 'Auto Spare-Parts & Garages',
      'target.5': 'Electronics & Mobile Phone Shops',
      'target.6': 'Fashion, Boutiques & Cosmetics',
      'target.7': 'Small FMCG Distributors',
      'target.8': 'Other Businesses Managing Stock & Sales',

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

      // How it Works
      'steps.kicker': 'Getting Started',
      'steps.title': 'Get Started in 3 Simple Steps',
      'steps.subtitle': 'No technical IT knowledge required. Simple, fast, and ready to use immediately.',
      'steps.1.title': '1. Create Free Account',
      'steps.1.desc': 'Sign up with your phone number and business name in under 60 seconds. No credit card needed.',
      'steps.2.title': '2. Add Stock & Start Selling',
      'steps.2.desc': 'Add your products and start ringing up sales on your phone or PC right away.',
      'steps.3.title': '3. Know Your Real Numbers',
      'steps.3.desc': 'Watch your profits, stock levels, and customer debts update in real-time wherever you are.',

      // Pricing Section
      'price.kicker': 'Simple Pricing',
      'price.title': 'Transparent Plans for Every Business',
      'price.subtitle': 'Start with a 14-day free trial. Plans start from just TZS 10,000 per month.',
      'price.1.badge': 'Starter',
      'price.1.title': 'Starter',
      'price.1.price': 'TZS 10,000',
      'price.1.period': '/ month',
      'price.1.trial': 'Includes 14-day free trial',
      'price.1.desc': 'Essential business management for single shops, dukas, and kiosks.',
      'price.1.b1': '1 Shop / Cashier register',
      'price.1.b2': 'Full POS & sales tracking',
      'price.1.b3': 'Stock & inventory management',
      'price.1.b4': 'Customer debts & PDF receipts',
      'price.1.cta': 'Start 14-Day Free Trial',
      'price.2.badge': 'Most Popular',
      'price.2.title': 'Business Pro',
      'price.2.price': 'TZS 25,000',
      'price.2.period': '/ month',
      'price.2.trial': 'Includes 14-day free trial',
      'price.2.desc': 'Everything you need to run a fast-paced retail or wholesale store.',
      'price.2.b1': 'Everything in Starter',
      'price.2.b2': 'Multiple staff & cashier accounts',
      'price.2.b3': 'Shift till cash reconciliation',
      'price.2.b4': 'Advanced sales & expense reports',
      'price.2.cta': 'Get Started Pro',
      'price.3.badge': 'Enterprise',
      'price.3.title': 'Multi-Branch',
      'price.3.price': 'Custom',
      'price.3.period': 'tailored solution',
      'price.3.desc': 'For businesses with multiple shops, warehouses, or large distribution.',
      'price.3.b1': 'Multiple business branches',
      'price.3.b2': 'Inter-branch stock transfers',
      'price.3.b3': 'Consolidated owner dashboard',
      'price.3.b4': 'Dedicated priority support',
      'price.3.cta': 'Contact Sales',

      // FAQ
      'faq.kicker': 'Common Questions',
      'faq.title': 'Frequently Asked Questions',
      'faq.subtitle': 'Everything you need to know about Zipoo and how it works for your business.',
      'faq.q1': 'Can I use Zipoo on my mobile phone?',
      'faq.a1': 'Yes! Zipoo is designed mobile-first. You can use it on any Android phone, iPhone, tablet, laptop, or desktop computer through your browser or by installing it as an app.',
      'faq.q2': 'What happens if the internet goes down?',
      'faq.a2': 'Zipoo has built-in offline support. You can continue ringing up POS sales and issuing receipts without interruption. Once your internet reconnects, all transactions sync automatically.',
      'faq.q3': 'Can I print receipts for my customers?',
      'faq.a3': 'Yes! Zipoo works with standard Bluetooth and USB thermal receipt printers (both 58mm and 80mm). You can also share invoices directly via WhatsApp.',
      'faq.q4': 'How does Zipoo help me track customer debts (madeni)?',
      'faq.a4': 'Every time you make a credit sale, Zipoo links it to the customer profile. You can see total unpaid debts at a glance, record partial repayments, and keep complete history.',
      'faq.q5': 'Is my business information safe and private?',
      'faq.a5': 'Absolutely. Your data is encrypted and backed up securely in modern cloud infrastructure. Your cashiers only see sales screens, while sensitive reports remain strictly private to the business owner.',
      'faq.q6': 'How much does Zipoo cost?',
      'faq.a6': 'You can start with a 14-day free trial without a credit card. Paid plans start from just TZS 10,000 per month for Starter, and TZS 25,000 per month for Business Pro.',

      // Final CTA
      'cta.title': 'Ready to take full control of your business?',
      'cta.subtitle': 'Join forward-thinking business owners in Tanzania who manage sales, stock, and profits with complete clarity.',
      'cta.primary': 'Start Free Today',
      'cta.secondary': 'Sign In to Account',
      'cta.footnote': 'Instant setup in 60 seconds • No credit card required',

      // Footer
      'footer.tagline': 'The modern business software for sales, stock, customer debts, and financial numbers.',
      'footer.prod': 'Product',
      'footer.co': 'Company',
      'footer.about': 'About Zipoo',
      'footer.pricing': 'Pricing',
      'footer.login': 'Sign In',
      'footer.register': 'Create Account',
      'footer.contact': 'Contact & Support',
      'footer.location': 'Dar es Salaam, Tanzania',
      'footer.rights': 'All rights reserved. Run your business. Know your numbers.',
    },

    sw: {
      // Nav
      'nav.whyZipoo': 'Kwanini Zipoo',
      'nav.features': 'Inachosaidia',
      'nav.devices': 'Simu & PC',
      'nav.status': 'Uwezo',
      'nav.pricing': 'Bei',
      'nav.faq': 'Maswali',
      'nav.login': 'Ingia',
      'nav.startFree': 'Anza Bure',

      // Hero
      'hero.title': 'Simamia biashara yako.<br><span class="highlight">Zijue namba zako.</span>',
      'hero.subtitle': 'Simamia mauzo, stock, wateja, matumizi na madeni kupitia simu au kompyuta yako — kwa urahisi, sehemu moja.',
      'hero.primaryCta': 'Anza Bure Sasa',
      'hero.secondaryCta': 'Ona Jinsi Inavyofanya Kazi',
      'hero.trust1': 'Imetengenezwa Tanzania 🇹🇿',
      'hero.trust2': 'Hauhitaji kadi ya benki',
      'hero.trust3': 'Inafanya kazi kwenye simu & kompyuta',

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
      'feat.1.desc': 'Rekodi mauzo ya kila siku, aina za malipo, na uelewe mwenendo wa duka lako.',
      'feat.2.title': 'Stock / Stoo',
      'feat.2.desc': 'Jua bidhaa ulizonazo, zinazouza zaidi, na pata taarifa kabla bidhaa hazijaisha.',
      'feat.3.title': 'Wateja',
      'feat.3.desc': 'Weka taarifa za wateja, namba za simu na rekodi zao za manunuzi sehemu moja.',
      'feat.4.title': 'Madeni ya Wateja',
      'feat.4.desc': 'Fuatilia wateja wanaokudaiwa, rekodi malipo ya awamu, na usipoteze kumbukumbu.',
      'feat.5.title': 'Matumizi',
      'feat.5.desc': 'Rekodi matumizi ya biashara na ujue wapi pesa zako zinakwenda.',
      'feat.6.title': 'Ripoti',
      'feat.6.desc': 'Ona namba zako muhimu za kila siku, wiki na mwezi bila kupiga hesabu kwa mkono.',

      // Available vs Coming Soon
      'status.kicker': 'Mpango Wetu wa Maendeleo',
      'status.title': 'Kile Kilichopo Sasa vs. Kinachokuja Zipoo',
      'status.subtitle': 'Tunaamini katika uwazi wa 100%: hivi ndivyo vinavyofanya kazi sasa na vile vinavyoendelea kujengwa.',
      'status.avail.title': 'Inafanya Kazi Sasa',
      'status.avail.pill': 'Iko Tayari',
      'status.avail.1': 'Kurekodi mauzo ya kila siku na kuuza kaunta',
      'status.avail.2': 'Orodha ya bidhaa na bei zake',
      'status.avail.3': 'Kuhifadhi majina na namba za wateja',
      'status.avail.4': 'Ufuatiliaji wa idadi ya bidhaa zilizobaki stoo',
      'status.avail.5': 'Kurekodi matumizi madogo ya duka',
      'status.avail.6': 'Dashibodi ya muhtasari wa mauzo na pesa',
      'status.avail.7': 'Kubadili lugha ya Kiingereza na Kiswahili',
      'status.coming.title': 'Kinachokuja Zipoo',
      'status.coming.pill': 'Kinaendelea Kujengwa',
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
      'target.kicker': 'Wanaotumia Zipoo',
      'target.title': 'Imejengwa kwa Biashara Zinazosimamia Bidhaa, Wateja na Mauzo',
      'target.subtitle': 'Imetengenezwa mahsusi kwa maduka ya reja reja na jumla nchini Tanzania.',
      'target.1': 'Maduka ya Reja Reja & Mini-Supermarkets',
      'target.2': 'Maduka ya Vifaa vya Ujenzi (Hardware)',
      'target.3': 'Wafanyabiashara wa Jumla & Wasambazaji',
      'target.4': 'Maduka ya Spea za Magari & Pikipiki',
      'target.5': 'Maduka ya Vifaa vya Umeme & Simu',
      'target.6': 'Maduka ya Nguo, Viatu & Vipodozi',
      'target.7': 'Wasambazaji Wadogo wa Bidhaa',
      'target.8': 'Biashara Nyingine zenye Stoo na Mauzo',

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

      // How it Works
      'steps.kicker': 'Jinsi ya Kuanza',
      'steps.title': 'Anza kwa Hatua 3 Rahisi',
      'steps.subtitle': 'Hauhitaji utaalamu wa IT. Ni rahisi, ya haraka, na iko tayari kutumika mara moja.',
      'steps.1.title': '1. Fungua Akaunti Bure',
      'steps.1.desc': 'Jiandikishe kwa namba yako ya simu na jina la biashara ndani ya sekunde 60. Hakuna kadi inayohitajika.',
      'steps.2.title': '2. Weka Bidhaa & Anza Kuuza',
      'steps.2.desc': 'Ingiza bidhaa zako na anza kurekodi mauzo kwenye simu au kompyuta yako mara moja.',
      'steps.3.title': '3. Zijue Namba Zako Halisi',
      'steps.3.desc': 'Tazama faida yako halisi, idadi ya bidhaa stoo, na madeni ya wateja popote ulipo.',

      // Pricing Section
      'price.kicker': 'Gharama Zetu',
      'price.title': 'Vifurushi Wazi kwa Kila Biashara',
      'price.subtitle': 'Anza na majaribio ya siku 14 bure. Vifurushi vinaanzia TZS 10,000 tu kwa mwezi.',
      'price.1.badge': 'Kifurushi cha Kuanzia',
      'price.1.title': 'Starter',
      'price.1.price': 'TZS 10,000',
      'price.1.period': '/ mwezi',
      'price.1.trial': 'Inajumuisha siku 14 bure za majaribio',
      'price.1.desc': 'Usimamizi muhimu wa mauzo na stoo kwa maduka madogo na wajasiriamali.',
      'price.1.b1': 'Duka 1 / kaunta 1 ya kuuzia',
      'price.1.b2': 'Kuuza kwenye POS & kurekodi mauzo',
      'price.1.b3': 'Usimamizi kamili wa stoo ya bidhaa',
      'price.1.b4': 'Madeni ya wateja & risiti za PDF',
      'price.1.cta': 'Anza Siku 14 Bure',
      'price.2.badge': 'Maarufu Zaidi',
      'price.2.title': 'Biashara Pro',
      'price.2.price': 'TZS 25,000',
      'price.2.period': '/ mwezi',
      'price.2.trial': 'Inajumuisha siku 14 bure za majaribio',
      'price.2.desc': 'Kila kitu unachohitaji kuendesha duka la kisasa la reja reja au jumla.',
      'price.2.b1': 'Kila kitu kilichopo kwenye Starter',
      'price.2.b2': 'Akaunti za wafanyakazi & wauzaji wengi',
      'price.2.b3': 'Upashanaji wa droo ya pesa (Shift)',
      'price.2.b4': 'Ripoti za kina za mauzo na matumizi',
      'price.2.cta': 'Jiunge na Pro',
      'price.3.badge': 'Biashara Kubwa',
      'price.3.title': 'Matawi Mengi',
      'price.3.price': 'Maelewano',
      'price.3.period': 'kulingana na mahitaji',
      'price.3.desc': 'Kwa biashara zenye maduka na maghala zaidi ya moja.',
      'price.3.b1': 'Usimamizi wa matawi mengi',
      'price.3.b2': 'Uhamisho wa bidhaa kati ya matawi',
      'price.3.b3': 'Dashibodi ya pamoja ya mmiliki',
      'price.3.b4': 'Usaidizi wa moja kwa moja wa kipaumbele',
      'price.3.cta': 'Wasiliana Nasi',

      // FAQ
      'faq.kicker': 'Maswali ya Kawaida',
      'faq.title': 'Maswali Yanayoulizwa Mara kwa Mara',
      'faq.subtitle': 'Kila unachopaswa kujua kuhusu Zipoo na jinsi itakavyosaidia biashara yako.',
      'faq.q1': 'Je, ninaweza kutumia Zipoo kwenye simu yangu ya mkononi?',
      'faq.a1': 'Ndio! Zipoo imetengenezwa kufanya kazi vizuri sana kwenye simu yoyote ya Android, iPhone, tablet, au kompyuta kupitia kivinjari chako au kwa kuipakua kama app.',
      'faq.q2': 'Nini kitatokea mtandao wa intaneti ukikatika?',
      'faq.a2': 'Zipoo inafanya kazi hata bila intaneti. Unaweza kuendelea kuuza kwenye POS na kutoa risiti bila kukwama. Pindi mtandao utakapounganishwa tena, taarifa zote zitajiweka sawa kiotomatiki.',
      'faq.q3': 'Je, ninaweza kuchapisha risiti kwa ajili ya wateja?',
      'faq.a3': 'Ndio! Zipoo inafanya kazi na printa ndogo za kawaida za risiti (thermal printers za 58mm au 80mm) zinazotumia Bluetooth au USB. Pia unaweza kutuma ankara moja kwa moja kwa WhatsApp.',
      'faq.q4': 'Je, Zipoo inanisaidiaje kufuatilia madeni ya wateja?',
      'faq.a4': 'Kila unapouza kwa mkopo, Zipoo huunganisha deni hilo na jina la mteja. Unaweza kuona jumla ya madeni unayodai, kurekodi malipo ya kidogo kidogo, na kutunza kumbukumbu kamili.',
      'faq.q5': 'Je, taarifa za biashara yangu ziko salama?',
      'faq.a5': 'Ndio, kwa 100%. Taarifa zako zimehifadhiwa kwa mifumo ya kisasa ya kidijitali yenye ulinzi wa hali ya juu. Wauzaji wako wanaona tu skrini ya kuuza, lakini ripoti za faida na siri za biashara ziko mikononi mwa mwenye duka pekee.',
      'faq.q6': 'Gharama ya Zipoo ni kiasi gani?',
      'faq.a6': 'Unaweza kuanza na majaribio ya bure ya siku 14 bila kadi ya benki. Vifurushi vya kulipia vinaanzia TZS 10,000 tu kwa mwezi kwa Starter, au TZS 25,000 kwa mwezi kwa Biashara Pro.',

      // Final CTA
      'cta.title': 'Uko tayari kusimamia biashara yako kwa uhakika?',
      'cta.subtitle': 'Ungana na wafanyabiashara wajanja kote Tanzania wanaofuatilia mauzo, stoo na faida kwa uwazi na usahihi mkubwa.',
      'cta.primary': 'Anza Bure Sasa',
      'cta.secondary': 'Ingia Kwenye Akaunti',
      'cta.footnote': 'Kuanza huchukua sekunde 60 tu • Hauhitaji kadi ya benki',

      // Footer
      'footer.tagline': 'Mfumo wa kisasa wa biashara kwa ajili ya mauzo, stoo, madeni ya wateja na ripoti za kifedha.',
      'footer.prod': 'Bidhaa',
      'footer.co': 'Kampuni',
      'footer.about': 'Kuhusu Zipoo',
      'footer.pricing': 'Gharama & Vifurushi',
      'footer.login': 'Ingia Kwenye Mfumo',
      'footer.register': 'Fungua Akaunti',
      'footer.contact': 'Wasiliana Nasi',
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
        ? `<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>`
        : `<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>`;
    });

    // Close when clicking any nav link inside drawer
    drawer.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => {
        drawer.classList.remove('open');
        toggleBtn.setAttribute('aria-expanded', 'false');
        toggleBtn.innerHTML = `<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>`;
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
  });
})();
