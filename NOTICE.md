# NOTICE - Third-Party Software Attributions

**Project:** Little ISMS Helper
**Version:** 1.0
**Last Updated:** 2025-11-10

This software includes components from third-party open source projects. This document contains the required attributions and license notices for these components.

---

## License Compliance Summary

This project uses **163 third-party packages**:

| License Type | Count | Commercial Use | Requirements |
|--------------|-------|----------------|--------------|
| MIT | 134 | ✅ Allowed | Attribution, preserve copyright notices |
| BSD-3-Clause | 26 | ✅ Allowed | Attribution, preserve copyright notices, no endorsement |
| LGPL-2.1 | 1 | ✅ Allowed | Dynamic linking permitted, attribution required |
| LGPL-2.1-or-later | 1 | ✅ Allowed | Dynamic linking permitted, attribution required |
| LGPL-3.0-or-later | 1 | ✅ Allowed | Dynamic linking permitted, attribution required |

**Overall Status:** ✅ **Compliant for commercial use**

For a detailed license report, see: [`docs/reports/license-report.md`](docs/reports/license-report.md)

---

## Major Components

### PHP Dependencies (via Composer)

This project includes **156 PHP packages** managed through Composer. The majority are licensed under permissive MIT or BSD licenses.

**Key Dependencies:**
- **Symfony Framework** (MIT) - Web application framework
- **Doctrine ORM** (MIT) - Database abstraction and ORM
- **API Platform** (MIT) - REST and GraphQL API framework
- **Twig** (BSD-3-Clause) - Template engine
- **Monolog** (MIT) - Logging library
- **PHPOffice/PhpSpreadsheet** (MIT) - Excel file handling
- **DomPDF** (LGPL-2.1) - PDF generation library

**LGPL Components (Special Note):**
- **dompdf/dompdf** (LGPL-2.1) - Used via dynamic linking, commercial use permitted
- **dompdf/php-font-lib** (LGPL-2.1-or-later) - Font handling library
- **dompdf/php-svg-lib** (LGPL-3.0-or-later) - SVG rendering library

> **Note:** LGPL libraries are used in compliance with their licensing terms. Dynamic linking is permitted for commercial software. Source code for LGPL components is available from their respective repositories.

### JavaScript Dependencies (via Symfony ImportMap)

This project includes **5 JavaScript packages** managed through Symfony ImportMap:

- **@hotwired/stimulus** (MIT) - JavaScript framework
- **@hotwired/turbo** (MIT) - HTML over the wire framework
- **chart.js** (MIT) - Charting library
- **bootstrap** (MIT) - UI framework
- **@popperjs/core** (MIT) - Tooltip and popover positioning

### Manually Included Packages

#### marked.js (MIT License)
- **Description:** A markdown parser and compiler
- **Version:** Latest (via CDN from jsdelivr.net)
- **Copyright:** Copyright (c) 2018+, MarkedJS and Christopher Jeffrey
- **Repository:** https://github.com/markedjs/marked
- **License:** MIT

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

#### FOSJsRoutingBundle (MIT License)
- **Description:** Symfony bundle for JavaScript routing - provides router.min.js
- **Version:** Bundled with Symfony UX
- **Copyright:** Copyright (c) FriendsOfSymfony
- **Repository:** https://github.com/FriendsOfSymfony/FOSJsRoutingBundle
- **License:** MIT

---

## License Types Explained

### MIT License
The MIT License is a permissive license that allows commercial use, modification, and distribution. The only requirements are:
- Include the copyright notice
- Include the license text
- No warranty is provided

### BSD-3-Clause License
The BSD 3-Clause License is similar to MIT but includes an additional clause:
- Include the copyright notice
- Include the license text
- May not use the names of copyright holders for endorsement without permission
- No warranty is provided

### LGPL (Lesser General Public License)
LGPL allows dynamic linking in commercial software without requiring the commercial software to be open source. Requirements:
- Attribution and license notice
- If you modify LGPL code, those modifications must be released under LGPL
- The LGPL library itself must remain open source
- Static linking has additional requirements (not applicable here - we use dynamic linking)

---

## Full Attribution List

### Symfony Ecosystem (MIT)
This project is built on the Symfony Framework and related components.

Copyright (c) 2004-2025 Fabien Potencier

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

**Symfony Components Used:**
- symfony/asset
- symfony/asset-mapper
- symfony/console
- symfony/doctrine-messenger
- symfony/dotenv
- symfony/expression-language
- symfony/form
- symfony/framework-bundle
- symfony/http-client
- symfony/intl
- symfony/mailer
- symfony/mime
- symfony/monolog-bundle
- symfony/notifier
- symfony/password-hasher
- symfony/process
- symfony/property-access
- symfony/property-info
- symfony/rate-limiter
- symfony/runtime
- symfony/security-bundle
- symfony/serializer
- symfony/stimulus-bundle
- symfony/string
- symfony/translation
- symfony/twig-bundle
- symfony/ux-chartjs
- symfony/ux-turbo
- symfony/validator
- symfony/web-link
- symfony/yaml

### Doctrine Project (MIT)
Copyright (c) 2006-2025 Doctrine Project

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction.

**Doctrine Components Used:**
- doctrine/dbal
- doctrine/doctrine-bundle
- doctrine/doctrine-migrations-bundle
- doctrine/orm

### Twig (BSD-3-Clause)
Copyright (c) 2009-2025 Twig Team

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
3. Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.

### DomPDF (LGPL-2.1)
Copyright (c) 2004 Benj Carson

This library is free software; you can redistribute it and/or modify it under the terms of the GNU Lesser General Public License as published by the Free Software Foundation; either version 2.1 of the License, or (at your option) any later version.

This library is used via dynamic linking. Source code is available at: https://github.com/dompdf/dompdf

### Bootstrap (MIT)
Copyright (c) 2011-2025 The Bootstrap Authors

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction.

### Chart.js (MIT)
Copyright (c) 2014-2025 Chart.js Contributors

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction.

---

## Complete Package List

For a complete, up-to-date list of all dependencies with their exact versions and licenses, please run:

```bash
./license-report.sh
```

This will generate a detailed report at `docs/reports/license-report.md` containing:
- All 163 packages with their licenses
- Commercial use analysis
- Compliance recommendations
- Full package details

---

## License Compliance Process

This project maintains license compliance through:

1. **Automated Reporting:** `./license-report.sh` generates compliance reports
2. **Regular Audits:** License compliance is reviewed before each release
3. **CI/CD Integration:** Automated license checks in the deployment pipeline
4. **Documentation:** This NOTICE file is updated with each significant dependency change

---

## Questions or Concerns?

If you have questions about the licensing of this software or any of its components:

1. Review the detailed license report: `./license-report.sh`
2. Check individual package licenses in their repositories
3. Consult with your legal department for specific compliance questions

---

## Source Code Availability

All third-party source code is available from the respective project repositories. For convenience:

- **PHP packages:** Listed in `composer.json` and `composer.lock`
- **JavaScript packages:** Listed in `importmap.php`
- **Source repositories:** Links provided in `docs/reports/license-report.md`

To install all dependencies:
```bash
composer install
```

---

**Last License Audit:** 2025-11-10
**License Report Status:** ✅ Compliant (160/163 packages approved for commercial use)
**Total Dependencies:** 163 packages (156 PHP, 5 JavaScript, 2 manual)

*This NOTICE file was generated as part of our open source license compliance process.*
