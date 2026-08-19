# Damage Solver Data

Extracted from the locally installed FINAL FANTASY XIV game files with Lumina.

## Scope

- Every standard battle job: tanks, healers, melee DPS, physical-ranged DPS, and magical-ranged DPS, including Blue Mage.
- Every Phantom Job currently present in `MKDSupportJob`.
- PvE player actions, transformed/enhanced actions, and traits only; PvP actions are excluded.

## Layout

```text
Role/
  role-icon.png
  Job/
    class-icon.png
    Abilities/Ability Name/
      icon.png
      info.json
      info.de.json
      info.fr.json
      info.ja.json
    Traits/Trait Name/
      icon.png
      info.json
      info.de.json
      info.fr.json
      info.ja.json
```

`info.json` is English. The other files contain German, French, and Japanese game text.

`description` is rendered at the current level cap for that standard or Phantom Job. `description_macro`
preserves the exact game macro string, including every level- and job-dependent branch, for solver use.
Numeric IDs and timing, cost, combo, targeting, status, category, and source metadata come directly from
the installed game sheets.
