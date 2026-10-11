# Terttus Commerce

## 1.10.12 – Kategorier foldet sammen som standard
- Kategoritræet starter med alle underkategorier foldet sammen ved indlæsning.
- Manuel udfoldning, Fold alle ud, Fold alle sammen og søgning fungerer fortsat.


## 1.10.11 – Dansk datoformat
- Tilbudsperiodens start- og slutdato vises og indtastes i dansk format: `DD-MM-ÅÅÅÅ TT:MM` (24-timers ur).
- Tidspunkter fortolkes i tidszonen Europe/Copenhagen.
- Ugyldige datoer og slutdato før startdato afvises i stedet for at slette tilbudsperioden.
- Eksempel: `30-10-2026 23:09`. Native browser-datovælger er erstattet med et tekstfelt for at sikre ens dansk visning på tværs af browserindstillinger.


## 1.10.10 – Moms på salgs- og tilbudspriser
- Produktmanagerens salgspris og tilbudspris indtastes og vises altid **inklusive moms**.
- Hvis WooCommerce er sat til at indtaste priser eksklusive moms, omregner produktmanageren begge beløb til WooCommerce-lagringspriser ved gemning og tilbage til bruttopriser ved redigering. WooCommerce's gældende produktafgiftsklasse og satser anvendes.
- Hvis WooCommerce er sat til priser inklusive moms, gemmes beløbene uden ekstra omregning.
- Eksisterende produkter ændres ikke automatisk. Åbn et berørt produkt, indtast den ønskede bruttopris og tilbudspris på ny og gem. Kontrollér derefter visningen i webshoppen og ryd eventuelt cache.
- Test begge prisindstillinger på et testprodukt før masseopdatering; prisvisning kan afhænge af WooCommerce's øvrige momsopsætning.


Et samlet og mere overskueligt administrationslag oven på WooCommerce til Terttus.dk.

## 1.1.0
- Nyt Terttus Commerce hovedmenu-system
- Dashboard med dagens omsætning, ordrer, estimeret bruttoavance og handlinger
- Produktoversigt med hurtig redigering via Terttus Product Manager
- Ordrecenter med statusoverblik og seneste ordrer
- Lageroversigt med kostpris, salgspris og estimeret avance
- Leverandøroversigt baseret på leverandørdata på produkterne
- Responsiv navigation og admin UI
- Bevarer den eksisterende produktmanager og WooCommerce som datamotor

## Produktmanager
- Opret og redigér native WooCommerce simple produkter
- Hovedbillede og galleri via WordPress Media Library
- SKU, EAN/GTIN, mærke og kategori
- Kostpris, salgspris og tilbudspris med live avanceberegning
- Lagerstatus og antal
- Leverandør, leverandørvarenummer og leverandør-URL
- Kort og lang beskrivelse
- Fleksible specifikationer
- Produkt-tjek, kladde/publicering og duplikering

## Roadmap
Bulk-redigering, importcenter, pluk/pak, retur/RMA, avancerede rapporter og automatiseringer.
