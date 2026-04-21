# Self-Hosted Fonts — FairyAurora v3.0

## Inter (Sans, Body + Headlines)

- Source: https://github.com/rsms/inter
- Licence: SIL Open Font License 1.1
- Weights: 400 (regular), 500 (medium), 600 (semibold), 700 (bold)
- Subset: latin only
- Delivered via `@fontsource/inter@5.0.16` (jsdelivr CDN mirror of upstream)

## JetBrains Mono (Labels, Metadata, Version-Strings)

- Source: https://github.com/JetBrains/JetBrainsMono
- Licence: SIL Open Font License 1.1
- Weights: 400, 500, 600
- Subset: latin only
- Delivered via `@fontsource/jetbrains-mono@5.0.16`

## Re-downloading

```bash
cd public/fonts
for w in 400 500 600 700; do
  curl -sSL -o inter-$w.woff2 \
    "https://cdn.jsdelivr.net/npm/@fontsource/inter@5.0.16/files/inter-latin-$w-normal.woff2"
done
for w in 400 500 600; do
  curl -sSL -o jetbrains-mono-$w.woff2 \
    "https://cdn.jsdelivr.net/npm/@fontsource/jetbrains-mono@5.0.16/files/jetbrains-mono-latin-$w-normal.woff2"
done
```
