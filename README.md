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

## Uppdateringar

### 2026-03-17 00:00

- Ändring: Initierade automatisk uppdateringslogg i README via git-hook.

### 2026-03-17 17:59

- Ändring: 0.0.7
- Filer: .githooks/commit-msg,.githooks/pre-commit app.js,app.php

### 2026-03-17 17:59

- Ändring: Har nu lagt till uppdateringslogg i README.md

### 2026-03-17 18:00

- Ändring: Uppdatering utan commit-rubrik

### 2026-03-17 18:05

- Ändring: Revertade automatisk git-hook för README för att undvika loopar. Uppdateringar i README görs manuellt framöver.

### 2026-03-17

- Ändring: Lade till skydd mot slumpning av salar utan placerade bänkar och defensiv visning för tomma placeringar.
- Ändring: Säkrade hantering av sparade tidsstämplar i state-API för att undvika felaktiga 1970-datum vid ogiltig input.

### 2026-03-17

- Ändring: Optimerade state-API och klienten till att spara enstaka salar, klasser och placeringar via upsert/delete i stället för att skicka hela listor varje gång.
- Ändring: Optimerade adminhantering så att klienten uppdaterar en användare i cachen efter ändring i stället för att ladda om hela användarlistan.
- Ändring: Minskat onödiga omräkningar i vissa listvyer genom att återanvända beräknade bänk- och placeringsvärden.
