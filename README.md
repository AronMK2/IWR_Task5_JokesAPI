## Telepítés és indítás

1. Hozd létre az adatbázist és a táblákat: futtasd le a projekt gyökerében található `database.sql` fájlt MySQL-ben. A szkript létrehozza a `task5_jokes` adatbázist.
2. A projekt gyökerében futtasd:
   composer install

4. Indítsd el a fejlesztői szervert:
   php -S 127.0.0.1:8000 router.php

5. Nyisd meg a `http://127.0.0.1:8000/` címet. Az első oldalbetöltés lekéri és elmenti a 10 viccet. A `logs` táblában tárolt utolsó kérés után 5 percen belül újabb JokeAPI-kérés nem történik.

## REST API

Az alap URL például `http://127.0.0.1:8000`; a Postman-kollekció `baseUrl` változójába ezt add meg, záró perjel nélkül.

| Metódus | Útvonal | Leírás |
| --- | --- | --- |
| GET | `/api/jokes` | Viccek listája, legújabbtól |
| GET | `/api/jokes/{id}` | Egy vicc lekérése |
| POST | `/api/jokes` | Vicc létrehozása |
| PUT | `/api/jokes/{id}` | Vicc teljes cseréje |
| PATCH | `/api/jokes/{id}` | Vicc részleges módosítása |
| DELETE | `/api/jokes/{id}` | Vicc törlése |

A POST, PUT és PATCH törzse JSON, fejléce `Content-Type: application/json`. Példa single viccre:

```json
{
  "type": "single",
  "joke": "A programozó kedvenc helye? A Foo Bar."
}
```

Twopart viccnél `type`, `setup` és `delivery` mezőket adj meg. A PATCH legalább egy mezőt vár; típusváltáskor az új típus összes szükséges szöveges mezőjét küldd el. A `type=single` vagy `type=twopart` szűrő opcionális a listázásnál.

## Postman

Importáld a `Task5_Jokes_API.postman_collection.json` fájlt Postmanbe. A kollekcióban a `baseUrl` alapértelmezett értéke `http://127.0.0.1:8000`; szükség esetén módosítsd az alkalmazás címére. Futtasd a **Task 5 – Jokes API** kollekciót sorban; a POST kérés automatikusan beállítja a `jokeId` változót a további tesztekhez. A kollekció ellenőrzi a sikeres CRUD-műveleteket, a törlés utáni 404-et, a 0 azonosítóra adott 400-at és az érvénytelen POST-ot.

## Hibakeresés

- Adatbázis-kapcsolati hibánál ellenőrizd, hogy a MySQL fut, a `database.sql` lefutott, és a `.env` értékei helyesek.
- Ha az oldal a JokeAPI hibáját jelzi, a korábban elmentett viccek megmaradnak; az alkalmazás újrapróbálkozik, ha az utolsó kérés óta több mint 5 perc eltelt.
- A fejlesztői PHP-szerver termináljában láthatók a szervernapló hibái.
