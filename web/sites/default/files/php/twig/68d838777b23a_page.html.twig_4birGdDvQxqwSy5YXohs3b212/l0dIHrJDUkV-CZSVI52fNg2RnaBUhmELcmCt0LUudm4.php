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

/* themes/custom/associatedps/templates/page.html.twig */
class __TwigTemplate_4c57375634190e42b42846e8f93f3c05 extends Template
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
        // line 5
        yield "  <header class=\"content-header clearfix\">
    <div class=\"layout-container\">
      <div class=\"logo-wrapper\">
        <a href=\"/\" class=\"logo\"><img src=\"/themes/custom/associatedps/logo.png\" alt=\"Associated Process Server\"></a>
      </div>
      <div class=\"page-title-header\">
        <div id=\"block-associatedps-page-title\" class=\"block block-core block-page-title-block\">
          <h1 class=\"page-title\">
            <span class=\"field field--name-title field--type-string field--label-hidden\">Associated Process Servers</span>
          </h1>
        </div>
        <div>
        ";
        // line 17
        if ( !(($context["user_name"] ?? null) === "Anonymous")) {
            // line 18
            yield "        <input type=\"hidden\" id=\"user_role\" class=\"logged-in-user-role\" value=";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["user_role"] ?? null), "html", null, true);
            yield ">
          <div class=\"login-btn\">
            <h2><a href=";
            // line 20
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["user_url"] ?? null), "html", null, true);
            yield ">";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["user_name"] ?? null), "html", null, true);
            yield "</a>
              <p class=\"dropdown-menu\">
                <a href=\"/user/logout\">Logout</a>
              </p>
            </h2>
          </div>
          ";
        }
        // line 27
        yield "        </div>
      </div>
    </div>
  </header>

  ";
        // line 32
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "pre_content", [], "any", false, false, true, 32), "html", null, true);
        yield "

  <div class=\"layout-container\">
    ";
        // line 35
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "breadcrumb", [], "any", false, false, true, 35), "html", null, true);
        yield "
    <main class=\"page-content clearfix\" role=\"main\">
      <div class=\"visually-hidden\"><a id=\"main-content\" tabindex=\"-1\"></a></div>
      ";
        // line 38
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "highlighted", [], "any", false, false, true, 38), "html", null, true);
        yield "
      ";
        // line 39
        if (CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "help", [], "any", false, false, true, 39)) {
            // line 40
            yield "        <div class=\"help\">
          ";
            // line 41
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "help", [], "any", false, false, true, 41), "html", null, true);
            yield "
        </div>
      ";
        }
        // line 44
        yield "      ";
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "content", [], "any", false, false, true, 44), "html", null, true);
        yield "
    </main>

  </div>
";
        $this->env->getExtension('\Drupal\Core\Template\TwigExtension')
            ->checkDeprecations($context, ["user_name", "user_role", "user_url", "page"]);        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "themes/custom/associatedps/templates/page.html.twig";
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
        return array (  112 => 44,  106 => 41,  103 => 40,  101 => 39,  97 => 38,  91 => 35,  85 => 32,  78 => 27,  66 => 20,  60 => 18,  58 => 17,  44 => 5,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "themes/custom/associatedps/templates/page.html.twig", "D:\\wamp64\\www\\web\\themes\\custom\\associatedps\\templates\\page.html.twig");
    }
    
    public function checkSecurity()
    {
        static $tags = ["if" => 17];
        static $filters = ["escape" => 18];
        static $functions = [];

        try {
            $this->sandbox->checkSecurity(
                ['if'],
                ['escape'],
                [],
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
