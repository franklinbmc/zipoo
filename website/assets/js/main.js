/**
 * Zipoo Marketing Website - Main JS
 * Bilingual (EN & SW) translation engine, mobile drawer, and FAQ accordion.
 */

(function () {
  'use strict';

  // ==========================================
  // BILINGUAL TRANSLATION DICTIONARY
  // ==========================================
  const translations = {
    en: {
      // Nav
      'nav.features': 'Features',
      'nav.howItWorks': 'How It Works',
      'nav.devices': 'Mobile & PC',
      'nav.pricing': 'Pricing',
      'nav.faq': 'FAQ',
      'nav.login': 'Login',
      'nav.startFree': 'Start Free',

      // Hero
      'hero.badge': 'Modern Business System • East Africa',
      'hero.title': 'Run your business.<br><span class="highlight">Know your numbers.</span>',
      'hero.subtitle': 'All-in-one software for retail, wholesale, and service businesses. Effortlessly track sales, inventory, customer debts, expenses, and real-time profits from your phone or PC.',
      'hero.startFree': 'Start Free Today',
      'hero.login': 'Sign In to Account',
      'hero.trustCard': 'No credit card required',
      'hero.trustOffline': 'Works offline & online',
      'hero.trustTrial': 'Instant access',

      // Hero Mockup
      'mockup.todaySales': "Today's Total Sales",
      'mockup.profit': 'Estimated Net Profit',
      'mockup.lowStock': 'Low Stock Alert',
      'mockup.recentTx': 'Live POS Sales Stream',
      'mockup.cash': 'Cash',
      'mockup.mpesa': 'Lipa kwa Simu',
      'mockup.bank': 'Bank Transfer',

      // Problems & Solutions
      'prob.kicker': 'Why Zipoo?',
      'prob.title': 'Stop Losing Money to Paperwork and Guesswork',
      'prob.subtitle': 'Traditional book recording causes unnoticed stock loss, forgotten customer debts, and inaccurate profit estimates.',
      'prob.1.tag': 'Common Problem',
      'prob.1.title': 'Missing Stock & Cash Mismatches',
      'prob.1.desc': 'Handwritten notebooks make it easy for stock to vanish and cash drawers to mismatch without anyone noticing.',
      'prob.1.sol': 'Zipoo shift till reconciliation records every coin, barcode scan, and cash movement automatically.',
      'prob.2.tag': 'Common Problem',
      'prob.2.title': 'Forgotten Customer Debts (Madeni)',
      'prob.2.desc': 'Customer debts scattered across multiple books get forgotten, disputed, or never collected.',
      'prob.2.sol': 'Automated customer debt ledgers with single-click WhatsApp payment reminders and clear balances.',
      'prob.3.tag': 'Common Problem',
      'prob.3.title': 'Stockouts on Fast-Selling Goods',
      'prob.3.desc': 'Running out of popular items unexpectedly means turning away paying customers and losing revenue.',
      'prob.3.sol': 'Smart low-stock warning indicators notify you in advance before critical products run out.',
      'prob.4.tag': 'Common Problem',
      'prob.4.title': 'Unknown Real Profit at End of Day',
      'prob.4.desc': 'High sales volume often tricks owners into thinking they made money when expenses ate up the margin.',
      'prob.4.sol': 'Real-time profit deduction that subtracts item cost and overhead expenses instantly.',

      // Features
      'feat.kicker': 'Core Capabilities',
      'feat.title': 'Everything You Need to Run Your Business',
      'feat.subtitle': '6 powerful pillars engineered specifically for retail shops, wholesalers, and growing enterprises.',
      'feat.1.title': 'POS & Quick Invoicing',
      'feat.1.desc': 'Fast touch checkout, thermal Bluetooth receipt printing, and professional PDF invoices sent directly via WhatsApp.',
      'feat.1.b1': 'Thermal receipt printing (58mm/80mm)',
      'feat.1.b2': 'Fast barcode camera scanner',
      'feat.2.title': 'Smart Inventory & Stock',
      'feat.2.desc': 'Track quantities, unit costs, supplier purchase orders, and stock movements across multiple warehouses with ease.',
      'feat.2.b1': 'Multi-store and warehouse support',
      'feat.2.b2': 'Automatic low-stock threshold alerts',
      'feat.3.title': 'Customer & Debt Ledgers',
      'feat.3.desc': 'Never lose track of credit sales again. Keep detailed customer profiles, credit limits, and historical debt payments.',
      'feat.3.b1': 'Instant WhatsApp payment reminders',
      'feat.3.b2': 'Customer statement & balance tracking',
      'feat.4.title': 'Expenses & Till Reconciliation',
      'feat.4.desc': 'Record daily shop expenses, rent, utilities, and reconcile cashier cash drawers with shift opening/closing balances.',
      'feat.4.b1': 'Cashier shift till variance tracking',
      'feat.4.b2': 'Expense categorization and cash accounts',
      'feat.5.title': 'Real-Time Financial Reports',
      'feat.5.desc': 'Clear daily, weekly, and monthly reports showing total sales, gross profit, net profit, and best-selling products.',
      'feat.5.b1': 'Downloadable PDF & Excel reports',
      'feat.5.b2': 'Top product performance metrics',
      'feat.6.title': 'Staff Roles & Security',
      'feat.6.desc': 'Assign your cashiers sales-only access while keeping profit margins, supplier costs, and financial summaries private.',
      'feat.6.b1': 'Owner vs. Cashier permission control',
      'feat.6.b2': 'Audit trail of every transaction',

      // Platforms (Mobile + PC)
      'plat.kicker': 'Cross-Platform Flexibility',
      'plat.title': 'Your Business in Your Pocket or On Your Counter',
      'plat.subtitle': 'Work smoothly across all your devices without purchasing expensive proprietary hardware.',
      'plat.phone.title': 'On Your Smartphone',
      'plat.phone.desc': 'Check sales numbers from home, record orders while visiting clients, or ring up sales from anywhere.',
      'plat.pc.title': 'On Your PC & Laptop',
      'plat.pc.desc': 'Enjoy an expansive counter POS experience with keyboard shortcuts, barcode scanners, and printer connectivity.',
      'plat.offline.title': 'Works When Internet Fails',
      'plat.offline.desc': 'Keep recording sales and printing receipts offline. Zipoo automatically synchronizes once your connection restores.',
      'plat.box.title': 'Zero Expensive Hardware Required',
      'plat.box.sub': 'Runs on any device you already own:',
      'plat.box.1': 'Android smartphones & tablets',
      'plat.box.2': 'iPhones & iPads',
      'plat.box.3': 'Windows laptops & desktops',
      'plat.box.4': 'Standard Bluetooth & USB thermal printers',

      // How it Works
      'steps.kicker': 'Simple Setup',
      'steps.title': 'Get Started in 3 Minutes',
      'steps.subtitle': 'No technical IT knowledge required. Simple, fast, and ready to use immediately.',
      'step.1.title': '1. Create Free Account',
      'step.1.desc': 'Sign up with your phone number and business name in under 60 seconds. No credit card needed.',
      'step.2.title': '2. Add Stock & Start Selling',
      'step.2.desc': 'Add products easily or upload via Excel. Ring up sales on POS and print receipts right away.',
      'step.3.title': '3. Know Your Real Numbers',
      'step.3.desc': 'Watch your profits, stock levels, and customer debts update in real-time from anywhere in the world.',

      // Audience
      'aud.kicker': 'Built For You',
      'aud.title': 'Designed for Modern Businesses in Tanzania',
      'aud.subtitle': 'Trusted by ambitious shop owners, retailers, and wholesalers across East Africa.',
      'aud.retail': 'Retail Shops & Mini-Markets',
      'aud.pharmacy': 'Pharmacies & Duka la Dawa',
      'aud.hardware': 'Hardware & Building Materials',
      'aud.wholesale': 'Wholesalers & Distributors',
      'aud.clothing': 'Fashion, Boutiques & Cosmetics',
      'aud.electronics': 'Electronics & Mobile Shops',
      'aud.autoparts': 'Auto Spare Parts & Garages',
      'aud.services': 'Services & Consultancies',

      // Pricing Preview
      'price.kicker': 'Simple Pricing',
      'price.title': 'Transparent Plans for Every Business Stage',
      'price.subtitle': 'Start free and upgrade as your business expands. No hidden charges.',
      'price.free.title': 'Starter Trial',
      'price.free.desc': 'Ideal for new shops testing modern digital business management.',
      'price.free.price': 'Free',
      'price.free.period': '14-day full access',
      'price.free.b1': 'Full POS & sales tracking',
      'price.free.b2': 'Stock & inventory management',
      'price.free.b3': 'Customer debts & ledgers',
      'price.free.b4': 'Receipt printing & PDF invoices',
      'price.free.cta': 'Start Free Trial',
      'price.pro.popular': 'Most Popular',
      'price.pro.title': 'Business Pro',
      'price.pro.desc': 'Everything you need to run a fast-paced retail or wholesale store.',
      'price.pro.price': 'TZS 25,000',
      'price.pro.period': '/ month',
      'price.pro.b1': 'Everything in Starter',
      'price.pro.b2': 'Multiple staff & cashier accounts',
      'price.pro.b3': 'WhatsApp debt reminder alerts',
      'price.pro.b4': 'Advanced profit & loss reports',
      'price.pro.b5': 'Shift till cash reconciliation',
      'price.pro.cta': 'Get Started Pro',
      'price.ent.title': 'Multi-Branch',
      'price.ent.desc': 'For businesses with multiple shops, warehouses, and branches.',
      'price.ent.price': 'Custom',
      'price.ent.period': 'tailored solution',
      'price.ent.b1': 'Multiple business branches',
      'price.ent.b2': 'Inter-branch stock transfers',
      'price.ent.b3': 'Consolidated owner dashboard',
      'price.ent.b4': 'Dedicated priority support',
      'price.ent.cta': 'Contact Sales',

      // FAQ
      'faq.kicker': 'Got Questions?',
      'faq.title': 'Frequently Asked Questions',
      'faq.subtitle': 'Everything you need to know about Zipoo and how it helps your business.',
      'faq.q1': 'Can I use Zipoo on my mobile phone?',
      'faq.a1': 'Yes! Zipoo is designed mobile-first. You can use it on any Android phone, iPhone, tablet, laptop, or desktop computer through your browser or by installing it as an app.',
      'faq.q2': 'What happens if the internet goes down?',
      'faq.a2': 'Zipoo has built-in offline support. You can continue ringing up POS sales and issuing receipts without interruption. Once your internet reconnects, all transactions sync automatically.',
      'faq.q3': 'Can I print receipts for my customers?',
      'faq.a3': 'Yes! Zipoo works with standard Bluetooth and USB thermal receipt printers (both 58mm and 80mm). You can also share professional PDF invoices directly via WhatsApp or email.',
      'faq.q4': 'How does Zipoo help me track customer debts (madeni)?',
      'faq.a4': 'Every time you make a credit sale, Zipoo links it to the customer profile. You can see total unpaid debts at a glance, record partial repayments, and send polite payment reminders on WhatsApp.',
      'faq.q5': 'Is my business information safe and private?',
      'faq.a5': 'Absolutely. Your data is encrypted and backed up securely in modern cloud infrastructure. Your cashiers only see sales screens, while sensitive profit margins and reports remain strictly private to the business owner.',

      // CTA
      'cta.title': 'Ready to take full control of your business?',
      'cta.subtitle': 'Join forward-thinking business owners in Tanzania who manage sales, stock, and profits with complete clarity.',
      'cta.start': 'Start Free Today',
      'cta.login': 'Sign In to Account',
      'cta.footnote': 'Instant setup in 60 seconds • No credit card required',

      // Footer
      'footer.tagline': 'The modern business operating system for sales, stock, customer debts, and financial reports.',
      'footer.prod': 'Product',
      'footer.features': 'Features',
      'footer.pos': 'Point of Sale (POS)',
      'footer.inventory': 'Stock & Inventory',
      'footer.debts': 'Customer Debts',
      'footer.reports': 'Profit Reports',
      'footer.co': 'Company',
      'footer.about': 'About Zipoo',
      'footer.pricing': 'Pricing Plans',
      'footer.login': 'Sign In',
      'footer.register': 'Create Account',
      'footer.contact': 'Contact & Support',
      'footer.location': 'Dar es Salaam, Tanzania',
      'footer.rights': 'All rights reserved. Run your business. Know your numbers.',
    },

    sw: {
      // Nav
      'nav.features': 'Vipengele',
      'nav.howItWorks': 'Jinsi Inavyofanya Kazi',
      'nav.devices': 'Simu & Kompyuta',
      'nav.pricing': 'Bei',
      'nav.faq': 'Maswali',
      'nav.login': 'Ingia',
      'nav.startFree': 'Anza Bure',

      // Hero
      'hero.badge': 'Mfumo wa Kisasa wa Biashara • Afrika Mashariki',
      'hero.title': 'Simamia biashara yako.<br><span class="highlight">Zijue namba zako.</span>',
      'hero.subtitle': 'Mfumo thabiti wa kidijitali kwa maduka ya reja reja, jumla na huduma. Fuatilia mauzo, stoo ya bidhaa, madeni ya wateja, matumizi na faida halisi kupitia simu au kompyuta yako.',
      'hero.startFree': 'Anza Bure Sasa',
      'hero.login': 'Ingia Kwenye Akaunti',
      'hero.trustCard': 'Hauhitaji kadi ya benki',
      'hero.trustOffline': 'Inafanya kazi mtandaoni & bila intaneti',
      'hero.trustTrial': 'Upatikanaji wa papo hapo',

      // Hero Mockup
      'mockup.todaySales': 'Mauzo ya Leo',
      'mockup.profit': 'Makadirio ya Faida Halisi',
      'mockup.lowStock': 'Tahadhari ya Bidhaa Zinazoisha',
      'mockup.recentTx': 'Mtiririko wa Mauzo ya POS',
      'mockup.cash': 'Pesa Taslimu (Cash)',
      'mockup.mpesa': 'Lipa kwa Simu',
      'mockup.bank': 'Benki',

      // Problems & Solutions
      'prob.kicker': 'Kwanini Zipoo?',
      'prob.title': 'Acha Kupoteza Pesa kwa Vitabu na Makadirio',
      'prob.subtitle': 'Kutumia madaftari husababisha bidhaa kupotea bila kujua, madeni kusahaulika, na kutokujua faida halisi ya biashara.',
      'prob.1.tag': 'Tatizo Kubwa',
      'prob.1.title': 'Bidhaa Kupotea & Pesa Kutotimia',
      'prob.1.desc': 'Kurekodi kwa mikono kwenye madaftari kunafanya bidhaa kupotea na pesa kwenye droo kutolingana bila muhusika kujulikana.',
      'prob.1.sol': 'Zipoo huhesabu shifti ya muuzaji na kupatanisha kila senti na stoo ya bidhaa papo hapo.',
      'prob.2.tag': 'Tatizo Kubwa',
      'prob.2.title': 'Madeni ya Wateja Kusahaulika',
      'prob.2.desc': 'Madeni yaliyoandikwa kwenye madaftari mbalimbali hupotea, kusahaulika, au kusababisha mabishano na wateja.',
      'prob.2.sol': 'Daftari la kisasa la madeni linalokupa orodha kamili na uwezo wa kutuma ukumbusho wa WhatsApp kwa mbofyo mmoja.',
      'prob.3.tag': 'Tatizo Kubwa',
      'prob.3.title': 'Bidhaa Zinazotoka Sana Kuisha Ghafla',
      'prob.3.desc': 'Kuishiwa bidhaa maarufu ghafla kunakufanya uwakatishe tamaa wateja na kupoteza mapato ya kila siku.',
      'prob.3.sol': 'Tahadhari ya kiotomatiki inakujulisha mapema kabla bidhaa zako muhimu hazijaisha stoo.',
      'prob.4.tag': 'Tatizo Kubwa',
      'prob.4.title': 'Kutojua Faida Halisi Mwisho wa Siku',
      'prob.4.desc': 'Kuwa na mauzo makubwa hakumaanishi unapata faida ikiwa matumizi na gharama za bidhaa hazijakatwa kwa usahihi.',
      'prob.4.sol': 'Hesabu ya moja kwa moja ya faida halisi baada ya kutoa gharama ya manunuzi na matumizi ya duka.',

      // Features
      'feat.kicker': 'Uwezo wa Mfumo',
      'feat.title': 'Kila Kitu Unachohitaji Kusimamia Biashara',
      'feat.subtitle': 'Nguzo 6 thabiti zilizotengenezwa mahsusi kwa maduka, wafanyabiashara wa jumla, na kampuni zinazokua.',
      'feat.1.title': 'Kuuza Haraka (POS) & Ankara',
      'feat.1.desc': 'Uza kwa urahisi, chapisha risiti za mashine ndogo za Bluetooth, na toa ankara (invoices) za PDF kutuma kwa WhatsApp.',
      'feat.1.b1': 'Kuchapisha risiti (58mm/80mm Bluetooth & USB)',
      'feat.1.b2': 'Kusoma barcode kwa kamera ya simu',
      'feat.2.title': 'Stoo & Udhibiti wa Bidhaa',
      'feat.2.desc': 'Fuatilia idadi ya bidhaa, bei ya kununulia, wauzaji wa jumla, na uhamisho wa stoo kati ya matawi kwa urahisi.',
      'feat.2.b1': 'Usaidizi wa stoo zaidi ya moja',
      'feat.2.b2': 'Tahadhari ya bidhaa zinazokaribia kuisha',
      'feat.3.title': 'Wateja & Usimamizi wa Madeni',
      'feat.3.desc': 'Usipoteze tena pesa za mauzo ya mkopo. Hifadhi rekodi kamili za wateja, kiasi cha deni, na malipo ya awamu.',
      'feat.3.b1': 'Kutuma ujumbe wa ukumbusho wa deni WhatsApp',
      'feat.3.b2': 'Taarifa kamili ya akaunti ya mteja',
      'feat.4.title': 'Matumizi & Upashanaji wa Droo (Shift)',
      'feat.4.desc': 'Rekodi matumizi ya duka kama kodi, umeme, mishahara, na linganisha hesabu ya droo ya pesa wakati wa kuanza na kufunga shifti.',
      'feat.4.b1': 'Kujua tofauti ya pesa iliyopo na inayotarajiwa',
      'feat.4.b2': 'Kupanga matumizi katika makundi',
      'feat.5.title': 'Ripoti za Kifedha za Wakati Halisi',
      'feat.5.desc': 'Pata ripoti za siku, wiki na mwezi zinazoonyesha mauzo, faida ghafi, faida halisi, na bidhaa zinazouza zaidi.',
      'feat.5.b1': 'Pakua ripoti za PDF na Excel',
      'feat.5.b2': 'Orodha ya bidhaa zilizouza zaidi',
      'feat.6.title': 'Usimamizi wa Wafanyakazi & Usalama',
      'feat.6.desc': 'Wape wauzaji uwezo wa kuuza pekee huku faida, gharama za manunuzi, na mipangilio ya siri ikibaki kwa mwenye duka.',
      'feat.6.b1': 'Ruhusa tofauti kwa mwenye duka na muuzaji',
      'feat.6.b2': 'Kumbukumbu ya kila muamala unaofanyika',

      // Platforms (Mobile + PC)
      'plat.kicker': 'Urahisi wa Matumizi',
      'plat.title': 'Biashara Yako Mkononi au Kaunta, Popote Ulipo',
      'plat.subtitle': 'Fanya kazi kwenye vifaa vyako ulivyo navyo sasa bila kulazimika kununua vifaa vya gharama kubwa.',
      'plat.phone.title': 'Kwenye Simu Yako ya Mkononi',
      'plat.phone.desc': 'Angalia mauzo ukiwa nyumbani, rekodi mauzo popote ulipo, au tuma ankara kwa wateja kwa urahisi.',
      'plat.pc.title': 'Kwenye Kompyuta & Laptop',
      'plat.pc.desc': 'Pata muonekano mpana wa kaunta wenye njia za mkato za keyboard, skana ya barcode, na printa za risiti.',
      'plat.offline.title': 'Inafanya Kazi Mtandao Ukikatika',
      'plat.offline.desc': 'Endelea kuuza na kutoa risiti hata intaneti ikikatika. Mfumo utasawazisha taarifa zote mtandao ukirudi.',
      'plat.box.title': 'Hauhitaji Vifaa vya Gharama Kubwa',
      'plat.box.sub': 'Inafanya kazi kwenye vifaa unavyomiliki tayari:',
      'plat.box.1': 'Simu na tablet za Android',
      'plat.box.2': 'iPhone na iPad za Apple',
      'plat.box.3': 'Laptop na kompyuta za mezani (Windows & Mac)',
      'plat.box.4': 'Printa za kawaida za risiti za Bluetooth & USB',

      // How it Works
      'steps.kicker': 'Hatua Rahisi',
      'steps.title': 'Anza Ndani ya Dakika 3 Tu',
      'steps.subtitle': 'Hauhitaji utaalamu wa IT. Ni rahisi, ya haraka, na iko tayari kutumika mara moja.',
      'step.1.title': '1. Fungua Akaunti Bure',
      'step.1.desc': 'Jiandikishe kwa namba yako ya simu na jina la biashara ndani ya sekunde 60. Hakuna gharama ya kuanza.',
      'step.2.title': '2. Weka Bidhaa & Anza Kuuza',
      'step.2.desc': 'Ingiza bidhaa zako au pakia kutoka Excel. Anza kuuza kwenye kaunta ya POS na kutoa risiti mara moja.',
      'step.3.title': '3. Zijue Namba Zako Halisi',
      'step.3.desc': 'Tazama faida yako halisi, idadi ya bidhaa zilizobaki, na madeni ya wateja yakisasishwa kwa wakati halisi popote ulipo.',

      // Audience
      'aud.kicker': 'Imejengwa Kwa Ajili Yako',
      'aud.title': 'Imetengenezwa Mahsusi kwa Wafanyabiashara wa Kitanzania',
      'aud.subtitle': 'Inaaminiwa na wamiliki wa maduka, wauzaji wa jumla, na watoa huduma kote nchini.',
      'aud.retail': 'Maduka ya Reja Reja & Mini-Supermarkets',
      'aud.pharmacy': 'Maduka ya Dawa (Pharmacies & DLDM)',
      'aud.hardware': 'Maduka ya Vifaa vya Ujenzi (Hardware)',
      'aud.wholesale': 'Wafanyabiashara wa Jumla & Wasambazaji',
      'aud.clothing': 'Maduka ya Nguo, Viatu & Vipodozi',
      'aud.electronics': 'Maduka ya Vifaa vya Umeme & Simu',
      'aud.autoparts': 'Spea za Magari, Pikipiki & Karakana',
      'aud.services': 'Watoa Huduma & Ofisi Ndogo',

      // Pricing Preview
      'price.kicker': 'Gharama Zetu',
      'price.title': 'Vifurushi Wazi kwa Kila Hatua ya Biashara',
      'price.subtitle': 'Anza bure na ujiunge na vifurushi vya juu biashara yako inavyopanuka.',
      'price.free.title': 'Majaribio ya Bure',
      'price.free.desc': 'Bora kwa duka jipya linalotaka kuanza kusimamia biashara kidijitali.',
      'price.free.price': 'Bure',
      'price.free.period': 'Siku 14 za matumizi kamili',
      'price.free.b1': 'Kuuza kwenye POS & kurekodi mauzo',
      'price.free.b2': 'Usimamizi kamili wa stoo ya bidhaa',
      'price.free.b3': 'Daftari la madeni ya wateja',
      'price.free.b4': 'Kuchapisha risiti & ankara za PDF',
      'price.free.cta': 'Anza Majaribio Bure',
      'price.pro.popular': 'Maarufu Zaidi',
      'price.pro.title': 'Biashara Pro',
      'price.pro.desc': 'Kila kitu unachohitaji kuendesha duka la kisasa la reja reja au jumla.',
      'price.pro.price': 'TZS 25,000',
      'price.pro.period': '/ mwezi',
      'price.pro.b1': 'Kila kitu kilichopo kwenye Starter',
      'price.pro.b2': 'Akaunti za wafanyakazi & wauzaji wengi',
      'price.pro.b3': 'Ukumbusho wa madeni kwa WhatsApp',
      'price.pro.b4': 'Ripoti za kina za faida na hasara',
      'price.pro.b5': 'Upashanaji wa droo ya pesa (Shift)',
      'price.pro.cta': 'Jiunge na Pro',
      'price.ent.title': 'Matawi Mengi',
      'price.ent.desc': 'Kwa biashara zenye maduka na maghala zaidi ya moja.',
      'price.ent.price': 'Maelewano',
      'price.ent.period': 'kulingana na mahitaji',
      'price.ent.b1': 'Usimamizi wa matawi mengi ya biashara',
      'price.ent.b2': 'Uhamisho wa bidhaa kati ya matawi',
      'price.ent.b3': 'Dashibodi ya pamoja ya mmiliki',
      'price.ent.b4': 'Usaidizi wa moja kwa moja wa kipaumbele',
      'price.ent.cta': 'Wasiliana Nasi',

      // FAQ
      'faq.kicker': 'Una Maswali?',
      'faq.title': 'Maswali Yanayoulizwa Mara kwa Mara',
      'faq.subtitle': 'Kila unachopaswa kujua kuhusu Zipoo na jinsi itakavyosaidia biashara yako.',
      'faq.q1': 'Je, ninaweza kutumia Zipoo kwenye simu yangu ya mkononi?',
      'faq.a1': 'Ndio! Zipoo imetengenezwa kufanya kazi vizuri sana kwenye simu yoyote ya Android, iPhone, tablet, au kompyuta kupitia kivinjari chako au kwa kuipakua kama app.',
      'faq.q2': 'Nini kitatokea mtandao wa intaneti ukikatika?',
      'faq.a2': 'Zipoo inafanya kazi hata bila intaneti. Unaweza kuendelea kuuza kwenye POS na kutoa risiti bila kukwama. Pindi mtandao utakapounganishwa tena, taarifa zote zitajiweka sawa kiotomatiki.',
      'faq.q3': 'Je, ninaweza kuchapisha risiti kwa ajili ya wateja?',
      'faq.a3': 'Ndio! Zipoo inafanya kazi na printa ndogo za kawaida za risiti (thermal printers za 58mm au 80mm) zinazotumia Bluetooth au USB. Pia unaweza kutuma ankara za PDF moja kwa moja kwa WhatsApp.',
      'faq.q4': 'Je, Zipoo inanisaidiaje kufuatilia madeni ya wateja?',
      'faq.a4': 'Kila unapouza kwa mkopo, Zipoo huunganisha deni hilo na jina la mteja. Unaweza kuona jumla ya madeni unayodai, kurekodi malipo ya kidogo kidogo, na kumtumia mteja ukumbusho kwa WhatsApp.',
      'faq.q5': 'Je, taarifa za biashara yangu ziko salama?',
      'faq.a5': 'Ndio, kwa 100%. Taarifa zako zimehifadhiwa kwa mifumo ya kisasa ya kidijitali yenye ulinzi wa hali ya juu. Wauzaji wako wanaona tu skrini ya kuuza, lakini ripoti za faida na siri za biashara ziko mikononi mwa mwenye duka pekee.',

      // CTA
      'cta.title': 'Uko tayari kusimamia biashara yako kwa uhakika?',
      'cta.subtitle': 'Ungana na wafanyabiashara wajanja kote Tanzania wanaofuatilia mauzo, stoo na faida kwa uwazi na usahihi mkubwa.',
      'cta.start': 'Anza Bure Sasa',
      'cta.login': 'Ingia Kwenye Akaunti',
      'cta.footnote': 'Kuanza huchukua sekunde 60 tu • Hauhitaji kadi ya benki',

      // Footer
      'footer.tagline': 'Mfumo wa kisasa wa biashara kwa ajili ya mauzo, stoo, madeni ya wateja na ripoti za kifedha.',
      'footer.prod': 'Bidhaa',
      'footer.features': 'Vipengele',
      'footer.pos': 'Kuuza Kaunta (POS)',
      'footer.inventory': 'Stoo & Bidhaa',
      'footer.debts': 'Madeni ya Wateja',
      'footer.reports': 'Ripoti za Faida',
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
      // Toggle icon between hamburger and close
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
        // Close others
        faqItems.forEach((other) => {
          if (other !== item) other.classList.remove('active');
        });
        // Toggle current
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
