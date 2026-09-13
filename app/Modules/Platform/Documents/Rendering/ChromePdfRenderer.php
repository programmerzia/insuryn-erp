<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents\Rendering;

use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * PDF through the Chrome binary in headless mode (DECISION D-34, instead of spatie/browsershot, which needs Node's puppeteer and a second
 * Chromium download): the HTML is written to a private temporary folder, Chrome prints it to PDF with its own throwaway profile, no header or
 * footer of its own, and every network request sent to a closed local proxy — a template can only reach what is embedded in it. Scripts cannot
 * be switched off with `--blink-settings=scriptEnabled=false` (headless printing then writes no PDF); TemplateBodyGuard refuses them instead.
 * Binary and timeout: config `erp.documents.chrome_binary` (env ERP_CHROME_BINARY) and `erp.documents.render_timeout_seconds`.
 */
final class ChromePdfRenderer implements PdfRenderer
{
    public function render(string $html): string
    {
        $binary = (string) config('erp.documents.chrome_binary', '/usr/bin/google-chrome');
        $folder = rtrim(sys_get_temp_dir(), '/').'/erp-pdf-'.Str::uuid7();
        File::ensureDirectoryExists($folder, 0700);
        try {
            $input = "{$folder}/document.html";
            $output = "{$folder}/document.pdf";
            file_put_contents($input, $html);
            $process = new Process([
                $binary, '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-extensions', '--disable-sync', '--disable-background-networking',
                '--disable-component-update', '--disable-default-apps', '--no-first-run', '--no-default-browser-check', '--mute-audio', '--hide-scrollbars',
                '--proxy-server=http://127.0.0.1:9', '--proxy-bypass-list=<-loopback>',
                "--user-data-dir={$folder}/profile", '--no-pdf-header-footer', "--print-to-pdf={$output}", 'file://'.$input,
            ], $folder, ['HOME' => $folder]);
            $process->setTimeout(max(5, (int) config('erp.documents.render_timeout_seconds', 60)));
            try {
                $process->run();
            } catch (\Symfony\Component\Process\Exception\RuntimeException $e) {
                throw $this->failed($binary, $e->getMessage());
            }
            $pdf = is_file($output) ? (string) file_get_contents($output) : '';
            if (! str_starts_with($pdf, '%PDF-')) {
                throw $this->failed($binary, 'exit '.$process->getExitCode().': '.mb_substr(trim($process->getErrorOutput()), -500));
            }

            return $pdf;
        } finally {
            File::deleteDirectory($folder);
        }
    }

    private function failed(string $binary, string $detail): BusinessRuleViolation
    {
        Log::error('Document PDF rendering failed', ['binary' => $binary, 'detail' => $detail]);

        return new BusinessRuleViolation('DOCUMENT_PDF_FAILED', 'The PDF could not be produced. Ask an administrator to check the document renderer (ERP_CHROME_BINARY) and try again.');
    }
}
