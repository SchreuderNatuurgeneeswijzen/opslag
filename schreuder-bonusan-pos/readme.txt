=== Schreuder Bonusan POS Bestellingen ===
Contributors: schreuder
Requires at least: 6.2
Requires PHP: 8.0
Requires Plugins: woocommerce
Stable tag: 1.10.0

Maakt per locatie een Bonusan Excel-bestelling vanuit WooCommerce/YITH POS-orders.

== Werkwijze ==
1. Installeer en activeer de plugin.
2. Ga naar WooCommerce > Bonusan bestelling.
3. Kies Baarn, Haarlem of Zwolle en de gewenste periode.
4. Klik op "Bestelling controleren".
5. Controleer de aantallen. Zoek indien nodig handmatig een extra Bonusan-product op naam of SKU en voeg het toe.
6. Handmatige aantallen en toegevoegde producten worden automatisch 24 uur bewaard.
7. Download het Excel-bestand of verzend het naar bestellen@bonusan.nl.

Je kunt deze tussenstand op ieder moment bekijken, dus ook vóórdat de YITH-kassa wordt gesloten. Klik opnieuw op controleren om verkopen die daarna zijn gedaan mee te nemen.

De plugin bewaart de originele locatiesjablonen. In het uitvoerbestand staan uitsluitend bestelde producten, zonder lege regels tussen de producten. Na succesvolle verzending worden de gebruikte orders gemarkeerd, zodat ze niet opnieuw worden meegenomen.

== Belangrijk bij YITH POS ==
YITH POS-versies gebruiken verschillende interne meta-velden voor winkel en kassa. Vul onder de plugininstellingen zo nodig per locatie het YITH store-ID, register-ID of de exacte locatie-meta-waarde in. Zonder ingesteld kenmerk gebruikt de plugin de handmatig gekozen locatie.

Automatisch blind verzenden bij het sluiten van een kassa is bewust niet geactiveerd: de bestelling moet volgens de gewenste werkwijze eerst gecontroleerd kunnen worden. De plugin luistert wel naar bekende YITH sluit-hooks, maar de daadwerkelijke verzending blijft een bevestigde handeling.

== Technische eisen ==
- WooCommerce actief
- PHP 8.0 of hoger
- PHP-extensie ZipArchive
- Werkende WordPress e-mail/SMTP-configuratie



= 1.3.0 =
* Orders met status Afgerond worden als echte kassaverkoop weergegeven.
* Orders met status In behandeling worden als webshopverkoop via de kassa weergegeven.
* Het controlescherm toont beide verkoopstromen apart, plus het totaal en het handmatig te bestellen aantal.
* Het eerste Excel-werkblad blijft de ongewijzigde Bonusan-importlijst met totaalaantallen.
* Een extra werkblad Verdeling toont per SKU de twee verkoopstromen afzonderlijk.
* Orders met status In de wacht worden niet meer automatisch meegenomen.

= 1.2.0 =
* Aantallen in de controlelijst kunnen vóór download en verzending worden overschreven.
* Het oorspronkelijke verkochte aantal blijft zichtbaar naast het aangepaste bestelaantal.
* Een bestelaantal van 0 sluit de productregel uit het Excel-bestand uit.
* Conceptcontroles zijn gekoppeld aan de ingelogde gebruiker.
* Tijdelijke bestanden krijgen een willekeurige naam en de tijdelijke map wordt afgeschermd.


== Changelog ==

= 1.10.0 =
* Producten importeren uit een Excel-turflijst. Bij Locatievoorraad kun je een Bonusan-turflijst (.xlsx, zoals Bestelling-Bonusan-turflijst Baarn/Haarlem/Zwolle) uploaden. De producten worden op SKU (kolom Artikel/EAN/GTIN) aan je webshop gekoppeld. Je ziet eerst een controle (nieuw, al gevolgd, niet in de webshop gevonden, consult, dubbel in het bestand) en kiest zelf wat wordt overgenomen. Het bestand wordt niet bewaard.
* Een nieuw gevolgd product wacht op de getelde beginvoorraad. Tot je voor dat product een voorraad invoert (tellen, levering of akkoord op een levering) worden er geen verkopen van afgeboekt en komt het niet in het aanvuladvies. Daarmee ontstaat geen negatieve voorraad door het toevoegen van veel producten. Producten die al gevolgd werden blijven ongewijzigd.
* Optioneel kan de kolom Aantal worden overgenomen als getelde beginvoorraad voor één gekozen locatie. In een turflijst is Aantal normaal een bestelaantal; daarom staat deze optie standaard uit en worden bestaande voorraden nooit overschreven.
* Sjabloon Zwolle hersteld: Bacteri 8 Sachets had artikelnummer 583 in plaats van 200583, waardoor een verkoop daarvan in Zwolle niet op de turflijst terechtkwam.

= 1.9.0 =
* Verzonden Bonusan-bestellingen zijn nu de basis voor de voorraad. Na definitief verzenden worden de gevolgde producten uit de bestelling (aantal > 0) als "onderweg" naar die locatie vastgelegd. De voorraad verandert dan nog niet.
* Bij Locatievoorraad staat een kaart "Onderweg – bestellingen bij Bonusan". Klopt de levering, klik dan op Akkoord: de bestelde aantallen komen op de voorraad van die locatie. Afwijkingen geef je per regel aan: ontvangen zoals besteld, deels ontvangen (rest volgt als nalevering), deels ontvangen (rest niet leverbaar), nog niets ontvangen (blijft onderweg) of niet leverbaar. Niet aangepaste regels worden als ontvangen zoals besteld verwerkt.
* Wat onderweg is telt mee in het aanvuladvies, zodat je niet dubbel bestelt. Het advies toont een kolom "Onderweg B/H/Z".
* Verwerken is veilig bij dubbel klikken of twee gebruikers: een regel kan maar één keer worden afgehandeld en een verouderd scherm geeft een melding in plaats van een dubbele boeking. Ieder ontvangen aantal staat in het voorraadlogboek als "Levering Bonusan ontvangen".
* Alleen producten die je bij Locatievoorraad volgt worden onderweg gezet; andere regels van de bestelling blijven buiten de voorraad.
* Bij een herverzending van reeds verzonden orders ontstaat ook een zending; vervalt die, zet de regels dan op niet leverbaar.
* Nieuwe tabel `sbp_inbound` (wordt bij de update aangemaakt).

= 1.8.1 =
* Aanvulwijze per gevolgd product. Alleen producten die Bonusan niet kan leveren (in de webshop verborgen en niet op de turflijst) worden vanuit Baarn naar Haarlem en Zwolle aangevuld, met extern aanvuladvies voor Baarn. Alle andere gevolgde producten bestellen Haarlem en Zwolle zelf bij Bonusan via de turflijst; daarvoor toont het advies alleen hoeveel die locatie zelf moet bestellen en er komt geen transferadvies.
* De wijze wordt automatisch bepaald (zichtbaarheid "Verborgen" en niet op de turflijst) en is per product te overschrijven in het voorraadscherm.
* Het advies op het Bonusan-bestelscherm is nu per gekozen locatie: Haarlem en Zwolle zien wat ze zelf moeten bestellen of van Baarn ontvangen; Baarn ziet wat naar Haarlem/Zwolle moet en het externe advies.
* Zoekresultaten bij "Product aan locatievoorraad toevoegen" tonen of het product verborgen of zichtbaar is in de webshop.

= 1.8.0 =
* Locatievoorraad volledig herbouwd op een eigen grootboek (tabellen `sbp_stock` en `sbp_stock_ledger`). Iedere mutatie is één database-transactie met vergrendelde voorraadrij: gelijktijdige kassaverkopen kunnen elkaar niet meer overschrijven en het saldo is altijd gelijk aan beginsaldo plus de som van het logboek.
* Orderboekingen zijn idempotent: per order en product wordt alleen het verschil tussen "gewenst" en "al geboekt" verwerkt. Meerdere hooks of opslagen van dezelfde order geven nooit een dubbele boeking; een mislukte boeking wordt na een minuut automatisch opnieuw geprobeerd.
* Order wijzigen na boeking (aantal, regel erbij/eraf), gedeeltelijke retour, volledige retour/annulering, naar de prullenbak verplaatsen of verwijderen: de voorraad volgt de order. (Teruggeboekte aantallen gaan terug naar de locatie van de verkoop.)
* Startmoment per product (UTC) in plaats van één globaal moment: verkopen van vóór het volgen van een product worden nooit afgeboekt, ook niet als de order later wordt bewerkt of afgerond. Hiermee is ook de tijdzonefout van v1.7.0 verholpen.
* Interne transfers zijn atomair, mogen nooit meer dan de bronvoorraad zijn en delen een transfer-ID in beide logregels. Handmatige uitboekingen kunnen de voorraad niet onder 0 brengen (verkopen wel: dat is de fysieke werkelijkheid).
* Voorraadscherm: alleen gewijzigde velden worden opgeslagen. Een gewijzigd voorraadgetal wordt alleen verwerkt als de voorraad sinds het laden van de pagina niet is veranderd; anders volgt een melding en wordt niets overschreven.
* Voorraadlogboek met datum, product-ID, SKU, productnaam, locatie, mutatie, voor/na, reden, order, notitie en gebruiker; filter per product en CSV-export van de volledige historie. Het oude optie-logboek (maximaal 2000 regels) wordt bij de update overgenomen.
* Verkooptempo komt uit het grootboek (netto: verkopen min retouren), wordt gecorrigeerd voor de periode waarover echt gegevens zijn en wordt onder 14 dagen niet gebruikt voor het slimme doeladvies.
* Foutmeldingen in het voorraadscherm worden nu getoond.
* Adminmeldingen (minimumvoorraad, kassaverkopen zonder locatie) worden 5 minuten gecachet en vertragen niet meer iedere beheerpagina.
* Bij de update krijgen reeds gevolgde producten een startmoment, en bestaande v1.7.0-boekingen worden niet opnieuw afgeboekt.

= 1.7.1 =
* Locatie van een kassa-order wordt bepaald uit het register-ID (`_yith_pos_register`) met exacte vergelijking. Vul bij de instellingen per locatie het register-ID in (niet het store-ID, dat is voor alle kassa's gelijk). Er wordt niet meer op delen van tekst gegokt.
* Verkopen van de extra kassa of een onbekende kassa (bijv. een testkassa) komen onder "Kassaverkopen zonder locatie" in het controlescherm. Pas na het kiezen van Baarn, Haarlem of Zwolle worden ze besteld. Dezelfde keuze wordt voor de locatievoorraad gebruikt en kan niet meer worden gewijzigd.
* Gewone webshoporders tellen nooit als fysieke kassaverkoop, ook niet wanneer ze zijn afgerond. Ze zijn alleen informatief (Baarn).
* Gedeeltelijk geretourneerde aantallen worden van de kassaverkoop afgetrokken.
* Definitief verzenden is vergrendeld per locatie (geen dubbele mails bij dubbelklik of herhaalde aanvraag). Na verzending wordt de controle direct ongeldig; mislukte markering van orders wordt gemeld in plaats van een foutmelding.
* Het Excel-bestand wordt niet meer gemaakt wanneer een product met aantal > 0 er niet in kan worden gezet; de melding noemt de SKU's. Nieuwe numerieke SKU's worden als getal weggeschreven.
* Het werkblad Verdeling is schema-correct (dimension vóór sheetData) en het eerste werkblad opent weer bovenaan.
* Oude concepten (ook uit v1.6.x) kunnen geen turflijstproduct meer in de Planner brengen. Bij een hersteld handmatig aantal waarvan de kassaverkoop sindsdien is veranderd, verschijnt een waarschuwing.
* SKU-sleutels worden bij handmatig toevoegen en bij het opslaan van aantallen overal genormaliseerd.
* Handmatig toevoegen toont de nieuwe regel alleen nog in de bestellijst (niet meer in de Planner- en adviestabel).
* HPOS-compatibiliteit gedeclareerd; orderlinks in het voorraadlog werken met HPOS.
* POS-herkenning strakker: `_yith_pos_order`/`_yith_pos_register` en meta die met `yith_pos_` begint; geen valse treffers meer op bijvoorbeeld "deposit".
* Niet gewijzigd (volgt in 1.8.0): atomaire voorraadboeking, startmoment locatievoorraad, voorraadscherm, gedeeltelijke retouren in locatievoorraad.

= 1.7.0 =
* Locatievoorraad toegevoegd binnen dezelfde Bonusan POS-plugin voor Baarn, Haarlem en Zwolle.
* Per gekozen product kunnen actuele voorraad, minimumvoorraad en gewenste voorraad per locatie worden ingesteld.
* Baarn fungeert als hoofdvoorraad; Haarlem en Zwolle krijgen eerst een intern aanvuladvies vanuit Baarn.
* Als Baarn na benodigde interne transfers op/onder de minimumvoorraad komt, toont de plugin een extern aanvuladvies.
* Gewone webshopverkopen van gevolgde producten worden automatisch van Baarn afgeboekt.
* YITH POS-verkopen worden van de herkende vaste locatie afgeboekt. Onbekende/extra kassa-orders wachten op handmatige keuze Baarn/Haarlem/Zwolle.
* Verkooporders worden slechts eenmaal afgeboekt; annulering/refund boekt een volledig verwerkte order terug.
* Oude orders van vóór het starten van v1.7.0 worden niet met terugwerkende kracht van de beginvoorraad afgeboekt.
* Interne transfers, handmatige voorraadcorrecties en leveringen worden ondersteund en gelogd.
* Voorraadlogboek toegevoegd met product, locatie, mutatie, voor/na, reden en ordernummer.
* Verkooptempo over 30/90 dagen wordt gebruikt voor een niet-bindend slim doelvoorraadadvies.
* Minimumvoorraad geeft een WordPress-waarschuwing en het aanvuladvies is ook zichtbaar in het Bonusan-bestelscherm.
* De bestaande strikte scheiding tussen Bonusan-turflijst en Planner blijft behouden.

= 1.6.2 =
* Bonusan/Schreuder-turflijst en planner zijn strikt van elkaar gescheiden.
* Plannerzoeker sluit alle producten uit die in het locatiesjabloon staan of door Schreuder Assistent als Bonusan-product zijn herkend.
* Een turflijstproduct kan niet meer handmatig aan de planner worden toegevoegd.
* Oude plannerregels met dezelfde SKU als een turflijstproduct worden onderdrukt/verwijderd.
* Gelijke plannerproducten blijven samengevoegd tot één regel met het totale aantal.


= 1.5.5 =
* YITH POS-orders met custom status Openstaand factuur worden meegenomen in de turflijst.
* Orderquery gebruikt alle geregistreerde WooCommerce-orderstatussen zodat custom statussen niet verdwijnen.

= 1.5.0 =
* Leeg zoekveld toegevoegd om een Bonusan-product handmatig op productnaam of SKU toe te voegen.
* Alleen producten die in het Bonusan-bestelsjabloon van de gekozen locatie staan kunnen handmatig worden toegevoegd.
* Handmatig toegevoegde producten worden zichtbaar gemarkeerd.
* Aangepaste aantallen en handmatig toegevoegde producten blijven 24 uur bewaard en worden bij opnieuw laden hersteld.
* Aantalwijzigingen worden automatisch na korte vertraging opgeslagen.
* Na succesvolle definitieve verzending wordt de tijdelijke 24-uursbestellijst gewist.

= 1.4.4 =
* Bonusan bestelling is nu een zelfstandig hoofdmenu-item in de linker WordPress-zijbalk.
* De bestaande bestel-, controle-, download-, herlaad- en verzendfunctionaliteit blijft ongewijzigd.
= 1.4.3 =
* Eerder definitief verzonden kassaverkopen kunnen via een bewuste optie opnieuw worden geladen, gecontroleerd, gedownload en indien nodig opnieuw verzonden.
* Extra waarschuwing en bevestiging bij het opnieuw verzenden van reeds verwerkte orders.

= 1.4.2 =
* Kassaproducten kunnen onbeperkt opnieuw worden geladen vóór definitieve verzending.
* Expliciete knop “Opnieuw laden” toegevoegd.
* Alleen afgeronde kassaverkopen die werkelijk als te bestellen meetellen worden na verzending als verwerkt gemarkeerd.
* Orders met status “In behandeling” blijven uitsluitend informatief en worden niet als besteld afgesloten.

= 1.4.1 =
* Instelbare BCC-adressen toegevoegd.
* Reply-To instelling toegevoegd.
* Verzendlogboek toegevoegd met locatie, gebruiker, aantallen en ontvangers.


= 1.5.3 =
* YITH POS-kassaverkopen met Directe bankoverschrijving (BACS) en status 'on-hold' worden nu behandeld als echte afgeronde kassaverkopen voor de Bonusan-bestellijst. Gewone webshop-BACS-orders blijven uitgesloten door de YITH POS-controle.


= 1.5.8 =
* Consulten worden uitgesloten van de turflijst en het planner-overzicht.
* Detectie op productnaam en productcategorie met `consult`.

= 1.5.7 =
* Planner-overzicht wordt altijd zichtbaar getoond.
* Niet-Bonusan kassaverkopen zonder SKU worden ook in het planner-overzicht opgenomen.

= 1.5.9 =
* Nieuwe Bonusan-producten die door Schreuder Assistent 2.1.0 zijn herkend, worden ook geaccepteerd wanneer de SKU nog niet in het locatie-sjabloon staat.
* Zulke producten worden automatisch als nieuwe regel aan het gegenereerde Bonusan Excel-bestand toegevoegd.
* Handmatig zoeken/toevoegen ondersteunt dezelfde Schreuder Assistent-herkenning.

= 1.6.0 =
* Aparte zoek- en invoegfunctie voor interne plannerproducten.
* Handmatig aan de planner toegevoegde producten komen niet op de Bonusan-turflijst.
* Plannerproducten worden 24 uur bewaard en kunnen apart worden verwijderd.
* Handmatig toegevoegde nieuwe Bonusan-producten uit Schreuder Assistent worden correct uit de 24-uurs conceptlijst hersteld.

= 1.6.1 =
* Handmatig toevoegen aan de Bonusan-turflijst en handmatig toevoegen aan de planner zijn technisch volledig gescheiden.
* Een turflijstproduct wordt niet meer automatisch ook in de planner getoond.
* Plannerverkopen van hetzelfde product worden samengevoegd tot één regel met het totale aantal, ook over meerdere bestellingen.
* Plannerregels zonder SKU worden op productnaam samengevoegd.
* Verwijderen van een handmatig plannerproduct wijzigt de Bonusan-turflijst niet.
