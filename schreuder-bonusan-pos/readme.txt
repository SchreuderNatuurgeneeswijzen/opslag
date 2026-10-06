=== Schreuder Bonusan POS Bestellingen ===
Contributors: schreuder
Requires at least: 6.2
Requires PHP: 8.0
Requires Plugins: woocommerce
Stable tag: 1.7.0

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
