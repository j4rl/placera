# Placera

Placera är ett digitalt verktyg för klassrumsplacering. Appen hjälper lärare att snabbt skapa, slumpa, justera, spara och skriva ut elevplaceringar i olika salar, med stöd för flera skolor i samma installation.

## Vem appen är till för

- Lärare som behöver skapa och hantera sittplatser på ett snabbt och tydligt sätt.
- Arbetslag och ämneslag som vill kunna återanvända salar och placeringar.
- Skoladministratörer som vill styra vilka användare som får tillgång till systemet på sin egen skola.
- Superadmin som hanterar skolor och användarbehörigheter mellan skolor.

## Vad appen gör

- Hanterar grupper och elevlistor.
- Importerar elevnamn till grupper från text/CSV/JSON/Excel med kolumnval och förhandsvisning.
- Hanterar salar och bänkplaceringar via visuell editor.
- Slumpar placeringar automatiskt utifrån vald grupp och sal.
- Låter användaren finjustera placeringar manuellt.
- Sparar placeringar för senare användning.
- Ger möjlighet till direktutskrift och nedladdning som PDF.

## Roller och skolor

- `teacher`: kan använda och hantera grupper/salar/placeringar inom sin skola enligt behörighet.
- `school_admin`: hanterar användare på sin egen skola.
- `superadmin`: hanterar användare och skolor globalt (inte grupper eller salar).
- Varje användare tillhör en skola (förutom superadmin).
- Data isoleras per skola så att användare arbetar inom sin egen skolkontext.
- Superadmin har en separat skolöversikt där skolor kan godkännas, sättas väntande, avslås eller inaktiveras.
- Endast skolor med status `Godkänd` tillåter vanliga användare att logga in.
- Skoladmin kan logga in även när skolan inte är godkänd, men då i read-only-läge utan åtgärder.

## Användar- och säkerhetsflöde

- Nya användare skickar en ansökan om konto.
- Skoladmin och superadmin kan godkänna/avslå ansökningar enligt behörighet.
- Superadmin kan godkänna skoladmins och skolor.
- Godkända användare på godkända skolor kan logga in och använda verktyget.
- Frivillig 2FA för användare (TOTP).
- Skola kan tvinga 2FA för alla användare via skolinställning.
- Backupkoder för 2FA finns för återinloggning vid förlorad enhet.
- Glömt lösenord-flöde finns med återställningstoken.
- Systemet sparar vem som skapat eller uppdaterat salar och placeringar, samt när det gjordes.
- Elevnamn i grupper och sparade placeringar krypteras i databasen.

## Vem som har utvecklat appen
Appen är utvecklad av Charlie Jarl för att underlätta klassrumsplacering. 
Då andra alternativ kräver prenumeration eller andra avgifter är denna gjord för att vara helt gratis.
Bra och hjälpsamma appar kan vara gratis.
Koden är öppen källkod och finns tillgänglig på GitHub.

---

## Uppdateringar

### 2026-03-28

- Ändring: Fullt stöd för flera skolor med isolering mellan skolor.
- Ändring: Ny global roll `superadmin` för att hantera användare och skolor.
- Ändring: Superadmin-dashboard med sektionen `Skolor` (details/summary) som visar status, användarantal och skoladmins.
- Ändring: Färgkodning för skolstatus i skol-listan: `Godkänd` grön, `Väntande` blå, `Avslagen`/`Inaktiverad` röd.
- Ändring: Endast användare på `Godkänd` skola kan logga in; skoladmin kan logga in read-only om skolan inte är godkänd.
- Ändring: Frivillig 2FA för användare samt möjlighet för skoladmin att kräva 2FA för hela skolan.
- Ändring: Backupkoder för 2FA tillagda.
- Ändring: Glömt lösenord / lösenordsåterställning tillagd.
- Ändring: Gruppimport förbättrad med stöd för text/CSV/JSON/Excel och bättre namntolkning.
- Ändring: Superadmin kan radera skolor med status `Avslagen`; då raderas skolans användare och kopplad data.
- Ändring: Radering av `Avslagen` skola kräver extra bekräftelse där skolnamnet måste skrivas in.

### 2026-03-24

- Ändring: Bytte språk i gränssnittet från klass/klasser till grupp/grupper.
- Ändring: Menyvalet heter nu `Hantera` för lärare och `Admin` för administratörer.
- Ändring: Lade till delningsläge för både salar och grupper: `Delad` (standard) eller `Egen`.
- Ändring: `Egen` gör att sal/grupp endast visas för ägaren och inte kan väljas av andra användare.
- Ändring: Lärarurval filtrerar nu bort privata (`Egen`) salar/grupper för andra användare.
- Ändring: Delade sparade placeringar från andra kräver nu att både sal och grupp är `Delad`.

### 2026-03-17 22:42

- Ändring: Lärare kan nu se och använda alla godkända grupper och salar, men får endast redigera/radera sina egna (server-side och UI-skydd).
- Ändring: Hantera-vyn för lärare visar nu grupp- och salhantering (användarhantering är fortsatt endast för admin).
- Ändring: Lade till lärarstyrt urval för Placera-vyn: lärare kan kryssa i vilka grupper och salar som ska vara valbara (default är inget valt).
- Ändring: Lade till ny endpoint för lärarurval (`api/teacher_selection.php`) samt tabell för urval i databas (`plc_teacher_placement_selection`).
- Ändring: Lade till indikator i adminlistor som visar hur många andra användare som valt en grupp/sal du äger.
- Ändring: Förbättrade urvals-kontrollen visuellt (chip/toggle) och gjorde vald/ej vald lika breda.
- Ändring: Lärare kan nu se sparade placeringar från andra lärare/admin när både grupp och sal matchar lärarens valda urval.
- Ändring: Delade sparade placeringar är skrivskyddade för icke-ägare; de kan öppnas men inte redigeras/raderas.
- Ändring: Vid skrivskyddad sparad placering används “Spara som ny” (skapar kopia i stället för att skriva över originalet).

### 2026-03-17 19:03

- Ändring: Lade till kryptering-at-rest för elevnamn i grupper och sparade placeringar via `includes/crypto.php`.
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

- Ändring: Optimerade state-API och klienten till att spara enstaka salar, grupper och placeringar via upsert/delete i stället för att skicka hela listor varje gång.
- Ändring: Optimerade adminhantering så att klienten uppdaterar en användare i cachen efter ändring i stället för att ladda om hela användarlistan.
- Ändring: Minskat onödiga omräkningar i vissa listvyer genom att återanvända beräknade bänk- och placeringsvärden.
