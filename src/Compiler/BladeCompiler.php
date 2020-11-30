<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\ViewEngine\Compiler;

use Hyperf\Utils\Arr;
use Hyperf\Utils\Collection;
use Hyperf\Utils\Str;
use InvalidArgumentException;

class BladeCompiler extends Compiler implements CompilerInterface
{
    use Concern\CompilesComments;
    use Concern\CompilesComponents;
    use Concern\CompilesConditionals;
    use Concern\CompilesEchos;
    use Concern\CompilesErrors;
    use Concern\CompilesIncludes;
    use Concern\CompilesInjections;
    use Concern\CompilesJson;
    use Concern\CompilesLayouts;
    use Concern\CompilesLoops;
    use Concern\CompilesRawPhp;
    use Concern\CompilesStacks;
    use Concern\CompilesTranslations;

    /**
     * All of the registered extensions.
     *
     * @var array
     */
    protected $extensions = [];

    /**
     * All custom "directive" handlers.
     *
     * @var array
     */
    protected $customDirectives = [];

    /**
     * All custom "condition" handlers.
     *
     * @var array
     */
    protected $conditions = [];

    /**
     * All of the registered precompilers.
     *
     * @var array
     */
    protected $precompilers = [];

    /**
     * The file currently being compiled.
     *
     * @var string
     */
    protected $path;

    /**
     * All of the available compiler functions.
     *
     * @var array
     */
    protected $compilers = [
        // 'Comments',
        'Extensions',
        'Statements',
        'Echos',
    ];

    /**
     * Array of opening and closing tags for raw echos.
     *
     * @var array
     */
    protected $rawTags = ['{!!', '!!}'];

    /**
     * Array of opening and closing tags for regular echos.
     *
     * @var array
     */
    protected $contentTags = ['{{', '}}'];

    /**
     * Array of opening and closing tags for escaped echos.
     *
     * @var array
     */
    protected $escapedTags = ['{{{', '}}}'];

    /**
     * The "regular" / legacy echo string format.
     *
     * @var string
     */
    protected $echoFormat = '\Hyperf\ViewEngine\T::e(%s)';

    /**
     * Array of footer lines to be added to template.
     *
     * @var array
     */
    protected $footer = [];

    /**
     * Array to temporary store the raw blocks found in the template.
     *
     * @var array
     */
    protected $rawBlocks = [];

    /**
     * The array of class component aliases and their class names.
     *
     * @var array
     */
    protected $classComponentAliases = [];

    /**
     * The array of class component namespaces to autoload from.
     *
     * @var array
     */
    protected $classComponentNamespaces = [];

    /**
     * Indicates if component tags should be compiled.
     *
     * @var bool
     */
    protected $compilesComponentTags = true;

    /**
     * @var array
     */
    protected $componentAutoload = [];

    /**
     * Compile the view at the given path.
     *
     * @param null|string $path
     */
    public function compile($path = null)
    {
        if ($path) {
            $this->setPath($path);
        }

        if (! is_null($this->cachePath)) {
            $contents = $this->compileString($this->files->get($this->getPath()));

            if (! empty($this->getPath())) {
                $contents = $this->appendFilePath($contents);
            }

            $this->files->put(
                $this->getCompiledPath($this->getPath()),
                $contents
            );
        }
    }

    /**
     * Get the path currently being compiled.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Set the path currently being compiled.
     */
    public function setPath(string $path)
    {
        $this->path = $path;
    }

    /**
     * Compile the given Blade template contents.
     */
    public function compileString(string $value): string
    {
        [$this->footer, $result] = [[], ''];

        // First we will compile the Blade component tags. This is a precompile style
        // step which compiles the component Blade tags into @component directives
        // that may be used by Blade. Then we should call any other precompilers.
        $value = $this->compileComponentTags(
            $this->compileComments($this->storeUncompiledBlocks($value))
        );

        foreach ($this->precompilers as $precompiler) {
            $value = call_user_func($precompiler, $value);
        }

        // Here we will loop through all of the tokens returned by the Zend lexer and
        // parse each one into the corresponding valid PHP. We will then have this
        // template as the correctly rendered PHP that can be rendered natively.
        foreach (token_get_all($value) as $token) {
            $result .= is_array($token) ? $this->parseToken($token) : $token;
        }

        if (! empty($this->rawBlocks)) {
            $result = $this->restoreRawContent($result);
        }

        // If there are any footer lines that need to get added to a template we will
        // add them here at the end of the template. This gets used mainly for the
        // template inheritance via the extends keyword that should be appended.
        if (count($this->footer) > 0) {
            $result = $this->addFooters($result);
        }

        return $result;
    }

    /**
     * Strip the parentheses from the given expression.
     */
    public function stripParentheses(string $expression): string
    {
        if (Str::startsWith($expression, '(')) {
            $expression = substr($expression, 1, -1);
        }

        return $expression;
    }

    /**
     * Register a custom Blade compiler.
     */
    public function extend(callable $compiler)
    {
        $this->extensions[] = $compiler;
    }

    /**
     * Get the extensions used by the compiler.
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    /**
     * Register an "if" statement directive.
     */
    public function if(string $name, callable $callback)
    {
        $this->conditions[$name] = $callback;

        $this->directive($name, function ($expression) use ($name) {
            return $expression !== ''
                    ? "<?php if (\\Hyperf\\ViewEngine\\Blade::check('{$name}', {$expression})): ?>"
                    : "<?php if (\\Hyperf\\ViewEngine\\Blade::check('{$name}')): ?>";
        });

        $this->directive('unless' . $name, function ($expression) use ($name) {
            return $expression !== ''
                ? "<?php if (! \\Hyperf\\ViewEngine\\Blade::check('{$name}', {$expression})): ?>"
                : "<?php if (! \\Hyperf\\ViewEngine\\Blade::check('{$name}')): ?>";
        });

        $this->directive('else' . $name, function ($expression) use ($name) {
            return $expression !== ''
                ? "<?php elseif (\\Hyperf\\ViewEngine\\Blade::check('{$name}', {$expression})): ?>"
                : "<?php elseif (\\Hyperf\\ViewEngine\\Blade::check('{$name}')): ?>";
        });

        $this->directive('end' . $name, function () {
            return '<?php endif; ?>';
        });
    }

    /**
     * Check the result of a condition.
     *
     * @param array $parameters
     */
    public function check(string $name, ...$parameters): bool
    {
        return call_user_func($this->conditions[$name], ...$parameters);
    }

    /**
     * Register a class-based component alias directive.
     */
    public function component(string $class, ?string $alias = null, string $prefix = '')
    {
        if (! is_null($alias) && Str::contains($alias, '\\')) {
            [$class, $alias] = [$alias, $class];
        }

        if (is_null($alias)) {
            $alias = Str::contains($class, '\\View\\Components\\')
                            ? collect(explode('\\', Str::after($class, '\\View\\Components\\')))->map(function ($segment) {
                                return Str::kebab($segment);
                            })->implode(':')
                            : Str::kebab(class_basename($class));
        }

        if (! empty($prefix)) {
            $alias = $prefix . '-' . $alias;
        }

        $this->classComponentAliases[$alias] = $class;
    }

    /**
     * Register an array of class-based components.
     */
    public function components(array $components, string $prefix = '')
    {
        foreach ($components as $key => $value) {
            if (is_numeric($key)) {
                static::component($value, null, $prefix);
            } else {
                static::component($key, $value, $prefix);
            }
        }
    }

    /**
     * Get the registered class component aliases.
     */
    public function getClassComponentAliases(): array
    {
        return $this->classComponentAliases;
    }

    /**
     * Register a class-based component namespace.
     */
    public function componentNamespace(string $namespace, string $prefix)
    {
        $this->classComponentNamespaces[$prefix] = $namespace;
    }

    /**
     * Get the registered class component namespaces.
     */
    public function getClassComponentNamespaces(): array
    {
        return $this->classComponentNamespaces;
    }

    /**
     * Get component autoload config.
     */
    public function getComponentAutoload(): array
    {
        return $this->componentAutoload;
    }

    /**
     * Set component autoload config.
     */
    public function setComponentAutoload(array $config)
    {
        $this->componentAutoload = $config;
    }

    /**
     * Register a component alias directive.
     */
    public function aliasComponent(string $path, ?string $alias = null)
    {
        $alias = $alias ?: Arr::last(explode('.', $path));

        $this->directive($alias, function ($expression) use ($path) {
            return $expression
                        ? "<?php \$__env->startComponent('{$path}', {$expression}); ?>"
                        : "<?php \$__env->startComponent('{$path}'); ?>";
        });

        $this->directive('end' . $alias, function ($expression) {
            return '<?php echo $__env->renderComponent(); ?>';
        });
    }

    /**
     * Register an include alias directive.
     */
    public function include(string $path, ?string $alias = null)
    {
        return $this->aliasInclude($path, $alias);
    }

    /**
     * Register an include alias directive.
     */
    public function aliasInclude(string $path, ?string $alias = null)
    {
        $alias = $alias ?: Arr::last(explode('.', $path));

        $this->directive($alias, function ($expression) use ($path) {
            $expression = $this->stripParentheses($expression) ?: '[]';

            return "<?php echo \$__env->make('{$path}', {$expression}, \\Hyperf\\Utils\\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>";
        });
    }

    /**
     * Register a handler for custom directives.
     *
     * @throws \InvalidArgumentException
     */
    public function directive(string $name, callable $handler)
    {
        if (! preg_match('/^\w+(?:::\w+)?$/x', $name)) {
            throw new InvalidArgumentException("The directive name [{$name}] is not valid. Directive names must only contain alphanumeric characters and underscores.");
        }

        $this->customDirectives[$name] = $handler;
    }

    /**
     * Get the list of custom directives.
     */
    public function getCustomDirectives(): array
    {
        return $this->customDirectives;
    }

    /**
     * Register a new precompiler.
     */
    public function precompiler(callable $precompiler)
    {
        $this->precompilers[] = $precompiler;
    }

    /**
     * Set the echo format to be used by the compiler.
     */
    public function setEchoFormat(string $format)
    {
        $this->echoFormat = $format;
    }

    /**
     * Set the "echo" format to double encode entities.
     */
    public function withDoubleEncoding()
    {
        $this->setEchoFormat('\Hyperf\ViewEngine\T::e(%s, true)');
    }

    /**
     * Set the "echo" format to not double encode entities.
     */
    public function withoutDoubleEncoding()
    {
        $this->setEchoFormat('\Hyperf\ViewEngine\T::e(%s, false)');
    }

    /**
     * Indicate that component tags should not be compiled.
     */
    public function withoutComponentTags()
    {
        $this->compilesComponentTags = false;
    }

    /**
     * Append the file path to the compiled string.
     *
     * @param string $contents
     * @return string
     */
    protected function appendFilePath($contents)
    {
        $tokens = $this->getOpenAndClosingPhpTokens($contents);

        if ($tokens->isNotEmpty() && $tokens->last() !== T_CLOSE_TAG) {
            $contents .= ' ?>';
        }

        return $contents . "<?php /**PATH {$this->getPath()} ENDPATH**/ ?>";
    }

    /**
     * Get the open and closing PHP tag tokens from the given string.
     */
    protected function getOpenAndClosingPhpTokens(string $contents): Collection
    {
        return collect(token_get_all($contents))
            ->pluck('0')
            ->filter(function ($token) {
                return in_array($token, [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG]);
            });
    }

    /**
     * Store the blocks that do not receive compilation.
     */
    protected function storeUncompiledBlocks(string $value): string
    {
        if (strpos($value, '@verbatim') !== false) {
            $value = $this->storeVerbatimBlocks($value);
        }

        if (strpos($value, '@php') !== false) {
            $value = $this->storePhpBlocks($value);
        }

        return $value;
    }

    /**
     * Store the verbatim blocks and replace them with a temporary placeholder.
     */
    protected function storeVerbatimBlocks(string $value): string
    {
        return preg_replace_callback('/(?<!@)@verbatim(.*?)@endverbatim/s', function ($matches) {
            return $this->storeRawBlock($matches[1]);
        }, $value);
    }

    /**
     * Store the PHP blocks and replace them with a temporary placeholder.
     */
    protected function storePhpBlocks(string $value): string
    {
        return preg_replace_callback('/(?<!@)@php(.*?)@endphp/s', function ($matches) {
            return $this->storeRawBlock("<?php{$matches[1]}?>");
        }, $value);
    }

    /**
     * Store a raw block and return a unique raw placeholder.
     */
    protected function storeRawBlock(string $value): string
    {
        return $this->getRawPlaceholder(
            array_push($this->rawBlocks, $value) - 1
        );
    }

    /**
     * Compile the component tags.
     */
    protected function compileComponentTags(string $value): string
    {
        if (! $this->compilesComponentTags) {
            return $value;
        }

        return (new ComponentTagCompiler(
            $this->classComponentAliases,
            $this->classComponentNamespaces,
            $this,
            $this->getComponentAutoload() ?: []
        ))->compile($value);
    }

    /**
     * Replace the raw placeholders with the original code stored in the raw blocks.
     *
     * @param string $result
     * @return string
     */
    protected function restoreRawContent($result)
    {
        $result = preg_replace_callback('/' . $this->getRawPlaceholder('(\d+)') . '/', function ($matches) {
            return $this->rawBlocks[$matches[1]];
        }, $result);

        $this->rawBlocks = [];

        return $result;
    }

    /**
     * Get a placeholder to temporary mark the position of raw blocks.
     *
     * @param int|string $replace
     */
    protected function getRawPlaceholder($replace): string
    {
        return str_replace('#', $replace, '@__raw_block_#__@');
    }

    /**
     * Add the stored footers onto the given content.
     */
    protected function addFooters(string $result): string
    {
        return ltrim($result, "\n")
                . "\n" . implode("\n", array_reverse($this->footer));
    }

    /**
     * Parse the tokens from the template.
     */
    protected function parseToken(array $token): string
    {
        [$id, $content] = $token;

        if ((int) $id === T_INLINE_HTML) {
            foreach ($this->compilers as $type) {
                $content = $this->{"compile{$type}"}($content);
            }
        }

        return $content;
    }

    /**
     * Execute the user defined extensions.
     *
     * @param string $value
     * @return string
     */
    protected function compileExtensions($value)
    {
        foreach ($this->extensions as $compiler) {
            $value = $compiler($value, $this);
        }

        return $value;
    }

    /**
     * Compile Blade statements that start with "@".
     *
     * @param string $value
     * @return string
     */
    protected function compileStatements($value)
    {
        return preg_replace_callback(
            '/\B@(@?\w+(?:::\w+)?)([ \t]*)(\( ( (?>[^()]+) | (?3) )* \))?/x',
            function ($match) {
                return $this->compileStatement($match);
            },
            $value
        );
    }

    /**
     * Compile a single Blade @ statement.
     *
     * @param array $match
     * @return string
     */
    protected function compileStatement($match)
    {
        if (Str::contains($match[1], '@')) {
            $match[0] = isset($match[3]) ? $match[1] . $match[3] : $match[1];
        } elseif (isset($this->customDirectives[$match[1]])) {
            $match[0] = $this->callCustomDirective($match[1], Arr::get($match, 3));
        } elseif (method_exists($this, $method = 'compile' . ucfirst($match[1]))) {
            $match[0] = $this->{$method}(Arr::get($match, 3));
        }

        return isset($match[3]) ? $match[0] : $match[0] . $match[2];
    }

    /**
     * Call the given directive with the given value.
     *
     * @param string $name
     * @param null|string $value
     * @return string
     */
    protected function callCustomDirective($name, $value)
    {
        if (Str::startsWith($value, '(') && Str::endsWith($value, ')')) {
            $value = Str::substr($value, 1, -1);
        }

        return call_user_func($this->customDirectives[$name], trim($value));
    }
}
