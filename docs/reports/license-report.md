# Lizenzbericht - Little ISMS Helper

**Generiert am:** 2025-11-13 16:48
**Status:** ✅ Lizenzkonform (160/163 Pakete freigegeben)

> **Wichtiger Hinweis:** Diese Auswertung fokussiert sich auf die **kommerzielle Nutzbarkeit** von Softwarekomponenten.
> Lizenzen können zusätzliche Pflichten (Attribution, Quelloffenlegung, NOTICE-Dateien) erfordern, die unbedingt eingehalten werden müssen.
> Die Einhaltung dieser Pflichten liegt in der Verantwortung des Nutzers. Bei Unsicherheiten wenden Sie sich an die Rechtsabteilung.

## Wichtigste Erkenntnisse

- **Gesamtstatus:** Lizenzkonform für kommerzielle Nutzung
- **Handlungsbedarf:** Keine unbekannten Lizenzen gefunden
- **Risikobewertung:** Niedrig (98.2% der Abhängigkeiten mit unkritischen Lizenzen)

## Zusammenfassung

| Einstufung | Beschreibung | Composer (PHP) | ImportMap (JS) | Manuell | Gesamt |
|------------|--------------|----------------|----------------|---------|--------|
| <span class="status-allowed">✅ Erlaubt</span> | Kommerzielle Nutzung ohne Copyleft (MIT, BSD, Apache-2.0) | 153 | 5 | 2 | **160** |
| <span class="status-restricted">⚠️ Eingeschränkt</span> | Kommerziell nutzbar mit Auflagen (MPL-2.0, EPL, CC-BY) | 0 | 0 | 0 | **0** |
| <span class="status-copyleft">🔄 Copyleft</span> | Kommerziell nutzbar mit Quelloffenlegungspflicht (GPL/LGPL) | 3 | 0 | 0 | **3** |
| <span class="status-not-allowed">❌ Nicht erlaubt</span> | Keine kommerzielle Nutzung (NC-Lizenzen) | 0 | 0 | 0 | **0** |
| <span class="status-unknown">❓ Unbekannt</span> | Manuelle Prüfung erforderlich | 0 | 0 | 0 | **0** |
| **Gesamt** | | **156** | **5** | **2** | **163** |

## Manuell eingebundene Pakete

Die folgenden Pakete werden nicht über Composer oder ImportMap verwaltet und wurden manuell hinzugefügt:

| Paket | Version | Lizenz | Status | Typ | Repository |
|-------|---------|--------|--------|-----|------------|
| marked.js | latest (CDN) | MIT | ✅ allowed | JavaScript (CDN) | https://github.com/markedjs/marked |
| FOSJsRoutingBundle | bundled | MIT | ✅ allowed | Symfony Bundle | https://github.com/FriendsOfSymfony/FOSJsRoutingBundle |

### marked.js
- **Beschreibung:** A markdown parser - loaded via CDN from jsdelivr.net
- **Copyright:** Copyright (c) 2018+, MarkedJS and Christopher Jeffrey
- **Lizenz:** MIT
- **Status:** Permissive Lizenz erlaubt kommerzielle Nutzung; NOTICE/Attribution beachten

### FOSJsRoutingBundle
- **Beschreibung:** Symfony bundle for JavaScript routing - provides router.min.js
- **Copyright:** Copyright (c) FriendsOfSymfony
- **Lizenz:** MIT
- **Status:** Permissive Lizenz erlaubt kommerzielle Nutzung; NOTICE/Attribution beachten

## Lizenztypen nach Häufigkeit

```
MIT           :  134 (82.2%)
BSD-3-CLAUSE  :   26 (16%)
LGPL-2.1      :    1 (0.6%)
LGPL-2.1-OR-LATER:    1 (0.6%)
LGPL-3.0-OR-LATER:    1 (0.6%)

```

## Handlungsempfehlungen

1. **Sofort:**
   - Keine sofortigen Maßnahmen erforderlich

2. **Kurzfristig:**
   - NOTICE-Datei mit Attributionen erstellen
   - Compliance-Dokumentation aktualisieren

3. **Mittelfristig:**
   - Lizenzprüfung in CI/CD-Pipeline integrieren
   - Alternativpakete für problematische Lizenzen evaluieren

## Details nach Ökosystem

<details>
<summary><strong>PHP-Pakete mit unbekannter/eingeschränkter Lizenz</strong> (0 Pakete)</summary>

Keine PHP-Pakete mit problematischen Lizenzen gefunden.

</details>

<details>
<summary><strong>JavaScript-Pakete mit unbekannter/eingeschränkter Lizenz</strong> (0 Pakete)</summary>

Keine JavaScript-Pakete mit problematischen Lizenzen gefunden.

</details>

<details>
<summary><strong>Vollständige Liste der PHP-Pakete</strong> (156 Pakete)</summary>

- api-platform/core@v4.2.3 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/api-platform/core.git
- composer/pcre@3.3.2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/composer/pcre.git
- composer/semver@3.4.4 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/composer/semver.git
- doctrine/collections@2.4.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/doctrine/collections.git
- doctrine/dbal@4.3.4 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/doctrine/dbal.git
- doctrine/deprecations@1.1.5 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/doctrine/deprecations.git
- doctrine/doctrine-bundle@3.0.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/doctrine/DoctrineBundle.git
- doctrine/doctrine-migrations-bundle@3.6.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/doctrine/DoctrineMigrationsBundle.git
- doctrine/event-manager@2.0.1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/doctrine/event-manager.git
- doctrine/inflector@2.1.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/doctrine/inflector.git
- doctrine/instantiator@2.0.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/doctrine/instantiator.git
- doctrine/lexer@3.0.1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/doctrine/lexer.git
- doctrine/migrations@3.9.4 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/doctrine/migrations.git
- doctrine/orm@3.5.7 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/doctrine/orm.git
- doctrine/persistence@4.1.1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/doctrine/persistence.git
- doctrine/sql-formatter@1.5.3 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/doctrine/sql-formatter.git
- dompdf/dompdf@v3.1.4 — Lizenz(e): LGPL-2.1 — Einstufung: copyleft | https://github.com/dompdf/dompdf.git
- dompdf/php-font-lib@1.0.1 — Lizenz(e): LGPL-2.1-or-later — Einstufung: copyleft | https://github.com/dompdf/php-font-lib.git
- dompdf/php-svg-lib@1.0.0 — Lizenz(e): LGPL-3.0-or-later — Einstufung: copyleft | https://github.com/dompdf/php-svg-lib.git
- egulias/email-validator@4.0.4 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/egulias/EmailValidator.git
- firebase/php-jwt@v6.11.1 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/firebase/php-jwt.git
- guzzlehttp/guzzle@7.10.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/guzzle/guzzle.git
- guzzlehttp/promises@2.3.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/guzzle/promises.git
- guzzlehttp/psr7@2.8.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/guzzle/psr7.git
- jms/metadata@2.8.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/schmittjoh/metadata.git
- knpuniversity/oauth2-client-bundle@v2.19.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/knpuniversity/oauth2-client-bundle.git
- league/oauth2-client@2.8.1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/thephpleague/oauth2-client.git
- maennchen/zipstream-php@3.2.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/maennchen/ZipStream-PHP.git
- markbaker/complex@3.0.2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/MarkBaker/PHPComplex.git
- markbaker/matrix@3.0.1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/MarkBaker/PHPMatrix.git
- masterminds/html5@2.10.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/Masterminds/html5-php.git
- monolog/monolog@3.9.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/Seldaek/monolog.git
- onelogin/php-saml@4.3.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/SAML-Toolkits/php-saml.git
- phpdocumentor/reflection-common@2.2.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/phpDocumentor/ReflectionCommon.git
- phpdocumentor/reflection-docblock@5.6.3 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/phpDocumentor/ReflectionDocBlock.git
- phpdocumentor/type-resolver@1.10.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/phpDocumentor/TypeResolver.git
- phpoffice/phpspreadsheet@5.2.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/PHPOffice/PhpSpreadsheet.git
- phpstan/phpdoc-parser@2.3.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/phpstan/phpdoc-parser.git
- psr/cache@3.0.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/php-fig/cache.git
- psr/clock@1.0.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/php-fig/clock.git
- psr/container@2.0.2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/php-fig/container.git
- psr/event-dispatcher@1.0.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/php-fig/event-dispatcher.git
- psr/http-client@1.0.3 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/php-fig/http-client.git
- psr/http-factory@1.1.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/php-fig/http-factory.git
- psr/http-message@2.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/php-fig/http-message.git
- psr/link@2.0.1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/php-fig/link.git
- psr/log@3.0.2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/php-fig/log.git
- psr/simple-cache@3.0.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/php-fig/simple-cache.git
- ralouphie/getallheaders@3.0.3 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/ralouphie/getallheaders.git
- robrichards/xmlseclibs@3.1.3 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/robrichards/xmlseclibs.git
- sabberworm/php-css-parser@v8.9.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/MyIntervals/PHP-CSS-Parser.git
- symfony/asset@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/asset.git
- symfony/asset-mapper@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/asset-mapper.git
- symfony/cache@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/cache.git
- symfony/cache-contracts@v3.6.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/cache-contracts.git
- symfony/clock@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/clock.git
- symfony/config@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/config.git
- symfony/console@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/console.git
- symfony/dependency-injection@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/dependency-injection.git
- symfony/deprecation-contracts@v3.6.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/deprecation-contracts.git
- symfony/doctrine-bridge@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/doctrine-bridge.git
- symfony/doctrine-messenger@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/doctrine-messenger.git
- symfony/dotenv@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/dotenv.git
- symfony/error-handler@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/error-handler.git
- symfony/event-dispatcher@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/event-dispatcher.git
- symfony/event-dispatcher-contracts@v3.6.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/event-dispatcher-contracts.git
- symfony/expression-language@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/expression-language.git
- symfony/filesystem@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/filesystem.git
- symfony/finder@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/finder.git
- symfony/flex@v2.9.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/flex.git
- symfony/form@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/form.git
- symfony/framework-bundle@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/framework-bundle.git
- symfony/http-client@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/http-client.git
- symfony/http-client-contracts@v3.6.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/http-client-contracts.git
- symfony/http-foundation@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/http-foundation.git
- symfony/http-kernel@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/http-kernel.git
- symfony/intl@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/intl.git
- symfony/mailer@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/mailer.git
- symfony/messenger@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/messenger.git
- symfony/mime@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/mime.git
- symfony/monolog-bridge@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/monolog-bridge.git
- symfony/monolog-bundle@v3.10.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/monolog-bundle.git
- symfony/notifier@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/notifier.git
- symfony/options-resolver@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/options-resolver.git
- symfony/password-hasher@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/password-hasher.git
- symfony/polyfill-intl-grapheme@v1.33.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/polyfill-intl-grapheme.git
- symfony/polyfill-intl-icu@v1.33.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/polyfill-intl-icu.git
- symfony/polyfill-intl-idn@v1.33.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/polyfill-intl-idn.git
- symfony/polyfill-intl-normalizer@v1.33.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/polyfill-intl-normalizer.git
- symfony/polyfill-mbstring@v1.33.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/polyfill-mbstring.git
- symfony/polyfill-php85@v1.33.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/polyfill-php85.git
- symfony/process@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/process.git
- symfony/property-access@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/property-access.git
- symfony/property-info@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/property-info.git
- symfony/rate-limiter@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/rate-limiter.git
- symfony/routing@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/routing.git
- symfony/runtime@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/runtime.git
- symfony/security-bundle@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/security-bundle.git
- symfony/security-core@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/security-core.git
- symfony/security-csrf@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/security-csrf.git
- symfony/security-http@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/security-http.git
- symfony/serializer@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/serializer.git
- symfony/service-contracts@v3.6.1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/service-contracts.git
- symfony/stimulus-bundle@v2.31.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/stimulus-bundle.git
- symfony/stopwatch@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/stopwatch.git
- symfony/string@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/string.git
- symfony/translation@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/translation.git
- symfony/translation-contracts@v3.6.1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/translation-contracts.git
- symfony/twig-bridge@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/twig-bridge.git
- symfony/twig-bundle@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/twig-bundle.git
- symfony/type-info@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/type-info.git
- symfony/ux-chartjs@v2.31.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/ux-chartjs.git
- symfony/ux-turbo@v2.31.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/ux-turbo.git
- symfony/validator@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/validator.git
- symfony/var-dumper@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/var-dumper.git
- symfony/var-exporter@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/var-exporter.git
- symfony/web-link@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/web-link.git
- symfony/yaml@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/yaml.git
- thenetworg/oauth2-azure@v2.2.2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/TheNetworg/oauth2-azure.git
- twig/extra-bundle@v3.22.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/twigphp/twig-extra-bundle.git
- twig/string-extra@v3.22.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/twigphp/string-extra.git
- twig/twig@v3.22.0 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/twigphp/Twig.git
- vich/uploader-bundle@v2.8.1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/dustin10/VichUploaderBundle.git
- webmozart/assert@1.12.1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/webmozarts/assert.git
- willdurand/negotiation@3.1.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/willdurand/Negotiation.git
- myclabs/deep-copy@1.13.4 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/myclabs/DeepCopy.git
- nikic/php-parser@v5.6.2 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/nikic/PHP-Parser.git
- phar-io/manifest@2.0.4 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/phar-io/manifest.git
- phar-io/version@3.2.1 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/phar-io/version.git
- phpunit/php-code-coverage@12.4.0 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/sebastianbergmann/php-code-coverage.git
- phpunit/php-file-iterator@6.0.0 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/sebastianbergmann/php-file-iterator.git
- phpunit/php-invoker@6.0.0 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/sebastianbergmann/php-invoker.git
- phpunit/php-text-template@5.0.0 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/sebastianbergmann/php-text-template.git
- phpunit/php-timer@8.0.0 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/sebastianbergmann/php-timer.git
- phpunit/phpunit@12.4.3 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/sebastianbergmann/phpunit.git
- sebastian/cli-parser@4.2.0 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/sebastianbergmann/cli-parser.git
- sebastian/comparator@7.1.3 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/sebastianbergmann/comparator.git
- sebastian/complexity@5.0.0 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/sebastianbergmann/complexity.git
- sebastian/diff@7.0.0 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/sebastianbergmann/diff.git
- sebastian/environment@8.0.3 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/sebastianbergmann/environment.git
- sebastian/exporter@7.0.2 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/sebastianbergmann/exporter.git
- sebastian/global-state@8.0.2 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/sebastianbergmann/global-state.git
- sebastian/lines-of-code@4.0.0 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/sebastianbergmann/lines-of-code.git
- sebastian/object-enumerator@7.0.0 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/sebastianbergmann/object-enumerator.git
- sebastian/object-reflector@5.0.0 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/sebastianbergmann/object-reflector.git
- sebastian/recursion-context@7.0.1 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/sebastianbergmann/recursion-context.git
- sebastian/type@6.0.3 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/sebastianbergmann/type.git
- sebastian/version@6.0.0 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/sebastianbergmann/version.git
- staabm/side-effects-detector@1.0.5 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/staabm/side-effects-detector.git
- symfony/browser-kit@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/browser-kit.git
- symfony/css-selector@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/css-selector.git
- symfony/debug-bundle@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/debug-bundle.git
- symfony/dom-crawler@v7.4.0-BETA2 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/dom-crawler.git
- symfony/maker-bundle@v1.64.0 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/maker-bundle.git
- symfony/web-profiler-bundle@v7.4.0-BETA1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/symfony/web-profiler-bundle.git
- theseer/tokenizer@1.2.3 — Lizenz(e): BSD-3-Clause — Einstufung: allowed | https://github.com/theseer/tokenizer.git
</details>

<details>
<summary><strong>Vollständige Liste der JavaScript-Pakete</strong> (5 Pakete)</summary>

- app@3.2.2 — Lizenz(e): MIT — Einstufung: allowed | https://npmjs.com/package/app
- @symfony/stimulus-bundle@7.3.0 — Lizenz(e): MIT — Einstufung: allowed | https://npmjs.com/package/@symfony/stimulus-bundle
- chart.js@3.9.1 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/chartjs/Chart.js
- bootstrap@5.3.3 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/twbs/bootstrap
- @popperjs/core@2.11.8 — Lizenz(e): MIT — Einstufung: allowed | https://github.com/popperjs/popper-core
</details>

## Lizenzeinstufungen im Detail

| Einstufung | Beschreibung | Anforderungen |
|------------|--------------|---------------|
| ✅ **Erlaubt** | Kommerzielle Nutzung erlaubt ohne Copyleft (MIT, BSD, Apache-2.0) | Attribution, Copyright-Hinweise beibehalten |
| ⚠️ **Eingeschränkt** | Kommerziell mit Auflagen (MPL-2.0, EPL, CDDL, CC-BY) | Attribution und spezifische Auflagen beachten |
| 🔄 **Copyleft** | Kommerziell mit Quelloffenlegung (GPL/AGPL/LGPL) | Quellcode offenlegen, Lizenz beibehalten |
| ❌ **Nicht erlaubt** | Keine kommerzielle Nutzung (NC-Lizenzen) | Nicht in kommerziellen Projekten verwenden |
| ❓ **Unbekannt** | Keine oder unklare Lizenzangabe | Manuelle Prüfung erforderlich |

---

*Dieser Bericht wurde automatisch generiert. Bei Fragen zur Lizenzkonformität wenden Sie sich an die Rechtsabteilung.*
