# Placera

Placera är ett digitalt verktyg för klassrumsplacering. Appen hjälper lärare att snabbt skapa, slumpa, justera, spara och skriva ut elevplaceringar i olika salar.

## Vem appen är till för

- Lärare som behöver skapa och hantera sittplatser på ett snabbt och tydligt sätt.
- Arbetslag och ämneslag som vill kunna återanvända salar och placeringar.
- Skoladministratörer som vill styra vilka användare som får tillgång till systemet.

## Vad appen gör

- Hanterar klasser och elevlistor.
- Hanterar salar och bänkplaceringar via visuell editor.
- Slumpar placeringar automatiskt utifrån vald klass och sal.
- Låter användaren finjustera placeringar manuellt.
- Sparar placeringar för senare användning.
- Ger möjlighet till direktutskrift och nedladdning som PDF.

## Användar- och säkerhetsflöde

- Nya användare skickar en ansökan om konto.
- Admin godkänner eller avslår ansökningar.
- Godkända användare kan logga in och använda verktyget.
- Systemet sparar vem som skapat eller uppdaterat salar och placeringar, samt när det gjordes.
- Elevnamn i klasser och sparade placeringar krypteras i databasen.

## Vem som har utvecklat appen
Appen är utvecklad av Charlie Jarl för att underlätta klassrumsplacering. 
Då andra alternativ kräver prenumeration eller andra avgifter är denna gjord för att vara helt gratis.
Bra och hjälpsamma appar kan vara gratis.
Koden är öppen källkod och finns tillgänglig på GitHub.

---

## Uppdateringar

### 2026-03-17 22:42

- Ändring: Lärare kan nu se och använda alla godkända klasser och salar, men får endast redigera/radera sina egna (server-side och UI-skydd).
- Ändring: Admin-vyn för lärare visar nu klass- och salhantering (användarhantering är fortsatt endast för admin).
- Ändring: Lade till lärarstyrt urval för Placera-vyn: lärare kan kryssa i vilka klasser och salar som ska vara valbara (default är inget valt).
- Ändring: Lade till ny endpoint för lärarurval (`api/teacher_selection.php`) samt tabell för urval i databas (`plc_teacher_placement_selection`).
- Ändring: Lade till indikator i adminlistor som visar hur många andra användare som valt en klass/sal du äger.
- Ändring: Förbättrade urvals-kontrollen visuellt (chip/toggle) och gjorde vald/ej vald lika breda.
- Ändring: Lärare kan nu se sparade placeringar från andra lärare/admin när både klass och sal matchar lärarens valda urval.
- Ändring: Delade sparade placeringar är skrivskyddade för icke-ägare; de kan öppnas men inte redigeras/raderas.
- Ändring: Vid skrivskyddad sparad placering används “Spara som ny” (skapar kopia i stället för att skriva över originalet).

### 2026-03-17 19:03

- Ändring: Lade till kryptering-at-rest för elevnamn i klasser och sparade placeringar via `includes/crypto.php`.
- Ändring: State-API dekrypterar namn vid läsning och krypterar vid skrivning så klienten fortsatt får samma dataformat.
- Ändring: Lade till migreringsskript för att kryptera befintliga namn i databasen (`scripts/migrate_encrypt_student_data.php`).
- Ändring: Lade till konfigurationskrav för `PLC_DATA_KEY`.

### 2026-03-17 18:57

- Ändring: Åtgärdade CSRF-validering så tomma eller saknade token alltid blockeras i både API och formulär.
- Ändring: Lade till inloggnings-throttling med tidsbaserad spärr vid upprepade misslyckade försök.
- Ändring: Säkrare sparflöde i klienten där ändringar bekräftas av server innan UI uppdateras, för att undvika osynk mellan klient och databas.
- Ändring: Förbättrad tillgänglighet med fler knapp-typer/aria-labels, keyboardstöd för sparade placeringar, Esc-stängning av modaler och tydligare fokusmarkering.
- Ändring: Prestandaförbättring i drag-and-drop genom att undvika full ommarkering av alla bänkar vid varje pekar-rörelse.
- Ändring: Lade till stöd för `prefers-reduced-motion` och minskade blockerande alert-dialoger till förmån för toast-meddelanden.

### 2026-03-17 17:59

- Ändring: Har nu lagt till uppdateringslogg i README.md för att dokumentera ändringar och förbättringar i appen över tid.

### 2026-03-17

- Ändring: Lade till skydd mot slumpning av salar utan placerade bänkar och defensiv visning för tomma placeringar.
- Ändring: Säkrade hantering av sparade tidsstämplar i state-API för att undvika felaktiga 1970-datum vid ogiltig input.

### 2026-03-17

- Ändring: Optimerade state-API och klienten till att spara enstaka salar, klasser och placeringar via upsert/delete i stället för att skicka hela listor varje gång.
- Ändring: Optimerade adminhantering så att klienten uppdaterar en användare i cachen efter ändring i stället för att ladda om hela användarlistan.
- Ändring: Minskat onödiga omräkningar i vissa listvyer genom att återanvända beräknade bänk- och placeringsvärden.
