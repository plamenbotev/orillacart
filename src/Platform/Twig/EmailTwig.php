<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Platform\CacheFolders;
use OrillaCart\Platform\PluginContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Twig\Environment;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Sandbox\Sandbox;
use Twig\Sandbox\SecurityPolicy;

/**
 * The Twig environment emails render in, apart from the storefront's: the shop's edits before the theme's and
 * the plugin's files, always in Twig's sandbox, as the shop writes its emails in the admin. A template may use
 * what the shop's own emails use, and read the arrays it is given, but calls no PHP: no methods, no properties,
 * no constants, no files.
 */
final readonly class EmailTwig
{
    public const array TAGS = ['apply', 'autoescape', 'block', 'extends', 'for', 'from', 'if', 'import', 'include', 'macro', 'set', 'with'];
    public const array FILTERS = [
        'abs', 'batch', 'capitalize', 'column', 'date', 'date_modify', 'default', 'e', 'escape', 'filter', 'first', 'format', 'join', 'json_encode', 'keys',
        'last', 'length', 'lower', 'map', 'merge', 'nl2br', 'number_format', 'raw', 'reduce', 'replace', 'reverse', 'round', 'slice', 'sort', 'split',
        'striptags', 'title', 'trim', 'upper', 'url_encode',
        'money', 'shop_date', 'shop_day', 'address_lines',
    ];
    public const array FUNCTIONS = ['block', 'cycle', 'date', 'include', 'max', 'min', 'parent', 'range', '__', '_n', 'prices_include_tax', 'slot', 'tax_number_label'];
    public const array TESTS = ['defined', 'divisible by', 'empty', 'even', 'iterable', 'mapping', 'none', 'null', 'odd', 'same as', 'sequence', 'true'];

    public function __construct(
        private PluginContext $context,
        private TwigFactory $files,
        private EditedTemplateLoader $edited,
        private Strictness $strictness,
        private ?ShopExtension $extension = null,
    ) {}

    public function create(): Environment
    {
        $twig = new Environment(new ChainLoader([$this->edited, new FilesystemLoader($this->files->templatePaths())]), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'debug' => \defined('WP_DEBUG') && WP_DEBUG,
            'cache' => CacheFolders::path('twig-emails', $this->context->version) ?? false,
            'auto_reload' => true,
        ]);
        if ($this->extension !== null) {
            $twig->addExtension($this->extension);
        }
        $policy = new SecurityPolicy(self::TAGS, self::FILTERS, [], [], self::FUNCTIONS, self::TESTS);
        $policy->setStrict(true);
        // Adds the sandbox to the environment, on for every template it renders.
        new Sandbox($twig, $policy);

        return $twig;
    }

    public function renderer(?NormalizerInterface $normalizer = null, LoggerInterface $logger = new NullLogger()): TwigRenderer
    {
        return new TwigRenderer($this->create(), $normalizer, $logger, $this->strictness);
    }
}
