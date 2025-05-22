# Amelia FluentCRM Tagger WordPress Bővítmény

**Verzió:** 1.2.8

Ez a WordPress bővítmény lehetővé teszi FluentCRM tagek automatikus hozzárendelését a felhasználókhoz az Amelia Booking bővítményben rögzített eseményfoglalásaik alapján. A tag-elés konfigurálhatóan az esemény előtt vagy után, meghatározott idővel történik.

## Főbb Funkciók

* **Automatikus Tag-elés:** FluentCRM tagek hozzáadása a felhasználókhoz az Amelia eseményekhez kapcsolódóan.
* **Időzített Műveletek:** Beállítható, hogy a tag-elés az esemény előtt vagy után hány nappal, órával és perccel történjen.
* **Rugalmas Szabályok:** Több, egymástól független tag-elési szabály is létrehozható különböző eseményekhez és tagekhez.
* **"Tag-elés Most" Funkció:** Lehetőség az egyes szabályok azonnali futtatására tesztelési vagy manuális tag-elési célból.
* **"Részletes Tájékoztató" CPT Integráció:** Egy egyedi bejegyzéstípus (Custom Post Type) segítségével részletes információk tárolhatók az eseményekről, melyek tartalma shortcode-dal beilleszthető a FluentCRM által küldött emailekbe.
* **Esemény és Tag Választás:** Könnyen kiválaszthatók a meglévő Amelia események és FluentCRM tagek a bővítmény beállítási felületén.

## Követelmények

* WordPress (legalább 5.2 verzió)
* PHP (legalább 7.2 verzió)
* **Amelia Booking** bővítmény (telepítve és aktiválva)
* **FluentCRM** bővítmény (telepítve és aktiválva)
* **Meta Box** bővítmény (telepítve és aktiválva) - a "Részletes Tájékoztató" CPT létrehozásához és kezeléséhez.

## Telepítés

1.  **Bővítmény Feltöltése:**
    * Töltsd le a bővítmény zip fájlját.
    * A WordPress admin felületén navigálj a "Bővítmények" > "Új hozzáadása" menüponthoz.
    * Kattints a "Bővítmény feltöltése" gombra, válaszd ki a zip fájlt, majd kattints a "Telepítés most" gombra.
    * A telepítés után aktiváld a bővítményt.
2.  **"Részletes Tájékoztató" CPT Létrehozása:**
    * A **Meta Box** bővítmény segítségével hozz létre egy új Egyedi Bejegyzéstípust (CPT).
    * **Javasolt slug:** `reszletes-tajekoztato` (a bővítmény kódjában `Amelia_Fluent_Tagger_Integrations::INFO_SHEET_CPT_SLUG` konstansként `reszletes-tajekoztato`-ként van megadva, de a kódban korábban `reszletes_taj` is szerepelt. Ellenőrizd a kódban az aktuális értéket, és használd azt! Ha a `reszletes_taj` van a kódban, akkor azt a slugot add meg.)
    * **Címkék (Labels):** Pl. "Részletes Tájékoztató", "Részletes Tájékoztatók".
    * Adj hozzá egy **egyedi mezőt** (Meta Box -> Custom Fields) ehhez a CPT-hez:
        * **Típus:** Szöveg (Text)
        * **ID/Slug:** `amelia_event_id` (a bővítmény kódjában `Amelia_Fluent_Tagger_Integrations::AMELIA_EVENT_ID_META_KEY` konstansként van megadva. Ellenőrizd a kódban az aktuális értéket!)
        * **Címke:** Pl. "Kapcsolódó Amelia Esemény ID"
        * Ebben a mezőben kell majd megadni annak az Amelia eseménynek a numerikus ID-ját, amelyhez a tájékoztató tartozik.

## Konfiguráció

A bővítmény beállításait a WordPress admin felületén, az **"Amelia Tagger"** menüpont alatt találod.

### Szabályok Létrehozása és Kezelése

1.  Navigálj az "Amelia Tagger" beállítási oldalára.
2.  Kattints az **"Új Szabály Hozzáadása"** gombra egy új szabály létrehozásához, vagy szerkeszd a meglévőket.
3.  Minden szabályhoz a következőket kell beállítani:
    * **Amelia Esemény:** Válaszd ki a legördülő listából azt az Amelia eseményt (vagy szolgáltatást, ha az Amelia úgy kezeli), amelyre a szabály vonatkozni fog. A listában az esemény neve és ID-ja is megjelenik.
    * **Időzítés (Cronhoz):**
        * Válaszd ki, hogy a tag-elés az **"Esemény Előtt"** vagy az **"Esemény Után"** történjen.
        * Add meg az időeltolást **napokban, órákban és percekben**. Ez az időzítés az automatikus, háttérben futó (cron) tag-elésre vonatkozik.
    * **FluentCRM Tag:** Válaszd ki a legördülő listából azt a FluentCRM taget, amelyet a rendszer hozzá fog adni a felhasználóhoz.
    * **FluentCRM Egyedi Mező (Info Sheet ID) (Opcionális):**
        * Add meg annak a FluentCRM egyedi kontakt mezőnek a kulcsát (slugját), ahova a "Részletes tájékoztató" CPT bejegyzés ID-ja mentésre kerüljön a tag-eléskor.
        * Javasolt érték: `amelia_info_sheet_id_for_email` (ezt a mezőt előbb létre kell hoznod a FluentCRM-ben).
        * Ha ezt a mezőt kitöltöd, a `[reszletes_tajekoztato_tartalom]` shortcode automatikusan megpróbálja betölteni a megfelelő tájékoztatót a FluentCRM emailben.
4.  **Szabályok Mentése:** Kattints a **"Szabályok Mentése"** gombra a változtatások érvényesítéséhez.

### "Tag-elés Most" Funkció

Minden egyes mentett szabály mellett található egy **"Tag-elés Most"** gomb.
* Erre a gombra kattintva az adott szabályhoz tartozó tag-elési logika azonnal lefut az összes releváns, **jóváhagyott státuszú** foglalásra, figyelmen kívül hagyva az időzítési beállításokat.
* Ez hasznos teszteléshez, vagy ha egy adott esemény résztvevőit azonnal szeretnéd tag-elni.
* A gomb alatt visszajelzés jelenik meg a művelet sikerességéről vagy az esetleges problémákról (pl. ha nem talál feldolgozható foglalást).

## "Részletes Tájékoztató" CPT Használata

1.  Hozd létre a "Részletes Tájékoztató" bejegyzéseket a WordPress adminban (a Meta Box által létrehozott CPT menüpont alatt).
2.  Minden egyes tájékoztató bejegyzés szerkesztőjében, a "Kapcsolódó Amelia Esemény ID" (vagy az általad elnevezett) egyedi mezőbe írd be annak az Amelia eseménynek a numerikus ID-ját, amelyhez ez a tájékoztató tartozik.
3.  A tájékoztató tartalmát (amit a WordPress szerkesztőjében írsz) a FluentCRM emailekben a következő shortcode segítségével tudod beilleszteni:

    ```shortcode
    [reszletes_tajekoztato_tartalom]
    ```
    * Ha a tag-elési szabályban megadtad a FluentCRM egyedi mező kulcsát (pl. `amelia_info_sheet_id_for_email`), és a tag-eléskor a bővítmény elmentette a tájékoztató ID-ját ebbe a mezőbe a kontaktnál, akkor a shortcode automatikusan megpróbálja betölteni a releváns tájékoztatót.
    * Alternatívaként, a shortcode-nak megadhatsz egy `event_id` attribútumot is, ha manuálisan szeretnéd meghatározni, melyik esemény tájékoztatója jelenjen meg: `[reszletes_tajekoztato_tartalom event_id="221"]` (ahol a 221 az Amelia esemény ID-ja).

## WordPress Cron Feladat

* A bővítmény egy WordPress cron feladatot használ (`aft_daily_tagging_event`) az automatikus, időzített tag-elések végrehajtásához.
* Alapértelmezés szerint ez a feladat **15 percenként** fut le.
* A cron feladat ellenőrzi az összes aktív szabályt, és ha egy foglalás megfelel az időzítési feltételeknek, hozzáadja a beállított taget a felhasználóhoz.
* A cron feladatok állapotát és futását ellenőrizheted pl. a "WP Crontrol" nevű bővítménnyel.

## Hibakeresés

* Ha problémát tapasztalsz, kapcsold be a WordPress hibakeresési módját a `wp-config.php` fájlban:
    ```php
    define( 'WP_DEBUG', true );
    define( 'WP_DEBUG_LOG', true ); // A hibákat a wp-content/debug.log fájlba menti
    define( 'WP_DEBUG_DISPLAY', false );
    @ini_set( 'display_errors', 0 );
    ```
* A bővítmény által generált naplóüzenetek (és az esetleges PHP hibák) a `wp-content/debug.log` fájlban fognak megjelenni. Ezek az üzenetek segíthetnek a probléma okának feltárásában.
* Ellenőrizd a WP Crontrol bővítményben, hogy az `aft_daily_tagging_event` cron esemény helyesen van-e ütemezve és lefut-e.

## Fejlesztői Megjegyzések (Fájlstruktúra)

A bővítmény a következő főbb könyvtárakból és fájlokból áll:

* `amelia-fluent-tagger.php`: A fő bővítményfájl, inicializálás, hook-ok.
* `admin/`
    * `class-aft-admin.php`: Az adminisztrációs felület logikája, szabályok kezelése.
    * `css/admin-style.css`: Admin felület stíluslapja.
    * `js/admin-script.js`: Admin felület JavaScript kódja.
* `includes/`
    * `class-aft-cron.php`: A WP Cron logika és az AJAX handler a "Tag-elés Most" funkcióhoz.
    * `class-aft-integrations.php`: Az Amelia és FluentCRM bővítményekkel való kommunikációért felelős függvények (események, tagek, foglalások lekérdezése).
    * `class-aft-shortcodes.php`: A `[reszletes_tajekoztato_tartalom]` shortcode logikája.
* `languages/`: Fordítási fájlok helye.

## Ismert Korlátok / Lehetséges Fejlesztések

* Jelenleg a "Tag-elés Most" gomb az összes, az adott eseményhez tartozó jóváhagyott foglalást feldolgozza. Lehetne finomítani, hogy pl. csak azokat, akik még nem kapták meg a taget. (Bár a jelenlegi logika már ellenőrzi, hogy a kontakt rendelkezik-e már a taggel.)
* Az Amelia adatbázis-struktúrájának esetleges jövőbeli változásai befolyásolhatják a foglalások lekérdezésének pontosságát.
* Részletesebb adminisztrátori naplózás a tag-elési műveletekről.

---

Remélem, ez a dokumentáció hasznos lesz!
