<?php

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Extension\CoreExtension;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Source;
use Twig\Template;
use Twig\TemplateWrapper;

/* themes/custom/associatedps/templates/navigation/menu--login-navigation.html.twig */
class __TwigTemplate_a08b93d2a18b147e441909719151f131 extends Template
{
    private Source $source;
    /**
     * @var array<string, Template>
     */
    private array $macros = [];

    public function __construct(Environment $env)
    {
        parent::__construct($env);

        $this->source = $this->getSourceContext();

        $this->parent = false;

        $this->blocks = [
        ];
        $this->sandbox = $this->extensions[SandboxExtension::class];
        $this->checkSecurity();
    }

    protected function doDisplay(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        // line 21
        yield "
";
        // line 22
        $macros["menus"] = $this->macros["menus"] = $this;
        // line 23
        yield "
";
        // line 31
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar($macros["menus"]->getTemplateForMacro("macro_menu_links", $context, 31, $this->getSourceContext())->macro_menu_links(...[($context["items"] ?? null), ($context["attributes"] ?? null), 0, ($context["menu_name"] ?? null)]));
        yield " ";
        yield " 

";
        $this->env->getExtension('\Drupal\Core\Template\TwigExtension')
            ->checkDeprecations($context, ["_self", "items", "attributes", "menu_name", "menu_level"]);        yield from [];
    }

    // line 33
    public function macro_menu_links($items = null, $attributes = null, $menu_level = null, $menu_name = null, ...$varargs): string|Markup
    {
        $macros = $this->macros;
        $context = [
            "items" => $items,
            "attributes" => $attributes,
            "menu_level" => $menu_level,
            "menu_name" => $menu_name,
            "varargs" => $varargs,
        ] + $this->env->getGlobals();

        $blocks = [];

        return ('' === $tmp = \Twig\Extension\CoreExtension::captureOutput((function () use (&$context, $macros, $blocks) {
            yield " ";
            yield " 
  ";
            // line 34
            $macros["menus"] = $this;
            yield " 
  ";
            // line 35
            yield " 
  ";
            // line 37
            $context["menu_classes"] = ["o-menu", ("c-menu-" . \Drupal\Component\Utility\Html::getClass(            // line 38
($context["menu_name"] ?? null)))];
            // line 40
            yield " 
  ";
            // line 41
            yield " 
  ";
            // line 43
            $context["submenu_classes"] = ["o-menu", (("c-menu-" . \Drupal\Component\Utility\Html::getClass(            // line 44
($context["menu_name"] ?? null))) . "__submenu")];
            // line 47
            yield "  ";
            if (($context["items"] ?? null)) {
                yield " 
    ";
                // line 48
                if ((($context["menu_level"] ?? null) == 0)) {
                    yield " 
      <ul";
                    // line 49
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["attributes"] ?? null), "addClass", [($context["menu_classes"] ?? null)], "method", false, false, true, 49), "html", null, true);
                    yield "> ";
                    yield " 
    ";
                } else {
                    // line 50
                    yield " 
      <ul";
                    // line 51
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["attributes"] ?? null), "removeClass", [($context["menu_classes"] ?? null)], "method", false, false, true, 51), "addClass", [($context["submenu_classes"] ?? null)], "method", false, false, true, 51), "html", null, true);
                    yield "> ";
                    yield " 
    ";
                }
                // line 53
                yield "    ";
                $context['_parent'] = $context;
                $context['_seq'] = CoreExtension::ensureTraversable(($context["items"] ?? null));
                foreach ($context['_seq'] as $context["_key"] => $context["item"]) {
                    yield " 
      ";
                    // line 54
                    yield " 
      ";
                    // line 56
                    $context["item_classes"] = [(("c-menu-" . \Drupal\Component\Utility\Html::getClass(                    // line 57
($context["menu_name"] ?? null))) . "__item"), "button-action button--secondary button--large edit", ((CoreExtension::getAttribute($this->env, $this->source,                     // line 59
$context["item"], "is_expanded", [], "any", false, false, true, 59)) ? ((("c-menu-" . \Drupal\Component\Utility\Html::getClass(($context["menu_name"] ?? null))) . "__item--expanded")) : ("")), ((CoreExtension::getAttribute($this->env, $this->source,                     // line 60
$context["item"], "is_collapsed", [], "any", false, false, true, 60)) ? ((("c-menu-" . \Drupal\Component\Utility\Html::getClass(($context["menu_name"] ?? null))) . "__item--collapsed")) : ("")), ((CoreExtension::getAttribute($this->env, $this->source,                     // line 61
$context["item"], "in_active_trail", [], "any", false, false, true, 61)) ? ((("c-menu-" . \Drupal\Component\Utility\Html::getClass(($context["menu_name"] ?? null))) . "__item--active-trail")) : (""))];
                    // line 64
                    yield "      ";
                    // line 65
                    yield "      ";
                    // line 66
                    $context["link_classes"] = [(("c-menu-" . \Drupal\Component\Utility\Html::getClass(                    // line 67
($context["menu_name"] ?? null))) . "__link"), "button-action button--secondary button--large edit"];
                    // line 71
                    yield "      <li";
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "attributes", [], "any", false, false, true, 71), "addClass", [($context["item_classes"] ?? null)], "method", false, false, true, 71), "html", null, true);
                    yield ">";
                    // line 72
                    yield "        ";
                    // line 73
                    yield "        ";
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->extensions['Drupal\Core\Template\TwigExtension']->getLink(CoreExtension::getAttribute($this->env, $this->source,                     // line 75
$context["item"], "title", [], "any", false, false, true, 75), CoreExtension::getAttribute($this->env, $this->source,                     // line 76
$context["item"], "url", [], "any", false, false, true, 76), CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source,                     // line 77
$context["item"], "attributes", [], "any", false, false, true, 77), "removeClass", [($context["item_classes"] ?? null)], "method", false, false, true, 77), "addClass", [($context["link_classes"] ?? null)], "method", false, false, true, 77)), "html", null, true);
                    // line 79
                    yield "
        ";
                    // line 80
                    if (CoreExtension::getAttribute($this->env, $this->source, $context["item"], "below", [], "any", false, false, true, 80)) {
                        // line 81
                        yield "          ";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar($macros["menus"]->getTemplateForMacro("macro_menu_links", $context, 81, $this->getSourceContext())->macro_menu_links(...[CoreExtension::getAttribute($this->env, $this->source, $context["item"], "below", [], "any", false, false, true, 81), ($context["attributes"] ?? null), (($context["menu_level"] ?? null) + 1), ($context["menu_name"] ?? null)]));
                        yield " ";
                        // line 82
                        yield "        ";
                    }
                    // line 83
                    yield "      </li>
    ";
                }
                $_parent = $context['_parent'];
                unset($context['_seq'], $context['_key'], $context['item'], $context['_parent']);
                $context = array_intersect_key($context, $_parent) + $_parent;
                // line 85
                yield "    </ul>
  ";
            }
            yield from [];
        })())) ? '' : new Markup($tmp, $this->env->getCharset());
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "themes/custom/associatedps/templates/navigation/menu--login-navigation.html.twig";
    }

    /**
     * @codeCoverageIgnore
     */
    public function isTraitable(): bool
    {
        return false;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getDebugInfo(): array
    {
        return array (  177 => 85,  170 => 83,  167 => 82,  163 => 81,  161 => 80,  158 => 79,  156 => 77,  155 => 76,  154 => 75,  152 => 73,  150 => 72,  146 => 71,  144 => 67,  143 => 66,  141 => 65,  139 => 64,  137 => 61,  136 => 60,  135 => 59,  134 => 57,  133 => 56,  130 => 54,  123 => 53,  117 => 51,  114 => 50,  108 => 49,  104 => 48,  99 => 47,  97 => 44,  96 => 43,  93 => 41,  90 => 40,  88 => 38,  87 => 37,  84 => 35,  80 => 34,  62 => 33,  52 => 31,  49 => 23,  47 => 22,  44 => 21,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "themes/custom/associatedps/templates/navigation/menu--login-navigation.html.twig", "D:\\wamp64\\www\\web\\themes\\custom\\associatedps\\templates\\navigation\\menu--login-navigation.html.twig");
    }
    
    public function checkSecurity()
    {
        static $tags = ["import" => 22, "macro" => 33, "set" => 37, "if" => 47, "for" => 53];
        static $filters = ["clean_class" => 38, "escape" => 49];
        static $functions = ["link" => 74];

        try {
            $this->sandbox->checkSecurity(
                ['import', 'macro', 'set', 'if', 'for'],
                ['clean_class', 'escape'],
                ['link'],
                $this->source
            );
        } catch (SecurityError $e) {
            $e->setSourceContext($this->source);

            if ($e instanceof SecurityNotAllowedTagError && isset($tags[$e->getTagName()])) {
                $e->setTemplateLine($tags[$e->getTagName()]);
            } elseif ($e instanceof SecurityNotAllowedFilterError && isset($filters[$e->getFilterName()])) {
                $e->setTemplateLine($filters[$e->getFilterName()]);
            } elseif ($e instanceof SecurityNotAllowedFunctionError && isset($functions[$e->getFunctionName()])) {
                $e->setTemplateLine($functions[$e->getFunctionName()]);
            }

            throw $e;
        }

    }
}
