<?php

declare(strict_types=1);

namespace App\Service\Export;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;

/**
 * The one place a PhpWord document is created (S10).
 *
 * PhpWord writes text into word/document.xml and docProps/core.xml verbatim unless
 * output escaping is switched on. Without it a record title such as
 * `x</w:t></w:r><w:fldSimple w:instr="INCLUDEPICTURE \\host\share"/>` becomes a Word
 * field that fetches a UNC path (NTLM hash leak) when the file is opened. The switch is
 * a process-wide static, so it is set on every creation — another library resetting it
 * cannot leave an export unescaped. Gate 92 (PHPStan rule `MarkupOutputRule`) rejects
 * `new PhpWord()` and `TemplateProcessor` anywhere else and any call that turns the
 * escaping off.
 */
final class DocxDocumentFactory
{
    public static function create(): PhpWord
    {
        Settings::setOutputEscapingEnabled(true);

        return new PhpWord();
    }
}
