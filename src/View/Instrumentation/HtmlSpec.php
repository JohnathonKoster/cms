<?php

namespace Statamic\View\Instrumentation;

/**
 * The HTML5 element classification tables shared by every HTML-aware
 * template analyzer. Both the Antlers sidecar parser and the context
 * scanner consume these; keeping them here guarantees the two can never
 * drift apart, and future engine front-ends reuse the same spec.
 *
 * Flat tables map lowercase element names to true for isset() lookups.
 */
class HtmlSpec
{
    const VOID_ELEMENTS = [
        'area' => true, 'base' => true, 'br' => true, 'col' => true,
        'embed' => true, 'hr' => true, 'img' => true, 'input' => true,
        'link' => true, 'meta' => true, 'source' => true, 'track' => true,
        'wbr' => true,
    ];

    const RAW_TEXT_ELEMENTS = [
        'script' => true, 'style' => true, 'textarea' => true,
        'title' => true, 'iframe' => true, 'noembed' => true,
        'noframes' => true, 'noscript' => true, 'xmp' => true,
    ];

    const P_CLOSING_ELEMENTS = [
        'address' => true, 'article' => true, 'aside' => true,
        'blockquote' => true, 'div' => true, 'dl' => true,
        'fieldset' => true, 'footer' => true, 'form' => true, 'h1' => true,
        'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true,
        'h6' => true, 'header' => true, 'hgroup' => true, 'hr' => true,
        'main' => true, 'menu' => true, 'nav' => true, 'ol' => true,
        'p' => true, 'pre' => true, 'search' => true, 'section' => true,
        'table' => true, 'ul' => true, 'li' => true, 'dt' => true,
        'dd' => true,
    ];

    const IMPLIED_CLOSE_TARGETS = [
        'li' => ['li' => true],
        'dt' => ['dt' => true, 'dd' => true],
        'dd' => ['dt' => true, 'dd' => true],
        'option' => ['option' => true],
        'optgroup' => ['option' => true, 'optgroup' => true],
        'tr' => ['tr' => true],
        'td' => ['td' => true, 'th' => true],
        'th' => ['td' => true, 'th' => true],
        'rp' => ['rp' => true, 'rt' => true],
        'rt' => ['rp' => true, 'rt' => true],
        'thead' => ['thead' => true, 'tbody' => true, 'tfoot' => true],
        'tbody' => ['thead' => true, 'tbody' => true, 'tfoot' => true],
        'tfoot' => ['thead' => true, 'tbody' => true, 'tfoot' => true],
        'caption' => ['caption' => true],
        'colgroup' => ['colgroup' => true],
    ];

    const SCOPE_BOUNDARIES = [
        'applet' => true, 'caption' => true, 'html' => true,
        'table' => true, 'td' => true, 'th' => true, 'marquee' => true,
        'object' => true, 'template' => true,
    ];

    const LIST_ITEM_SCOPE_BOUNDARIES = [
        'applet' => true, 'caption' => true, 'html' => true,
        'table' => true, 'td' => true, 'th' => true, 'marquee' => true,
        'object' => true, 'template' => true, 'ol' => true, 'ul' => true,
    ];

    const BUTTON_SCOPE_BOUNDARIES = [
        'applet' => true, 'caption' => true, 'html' => true,
        'table' => true, 'td' => true, 'th' => true, 'marquee' => true,
        'object' => true, 'template' => true, 'button' => true,
    ];

    const TABLE_SCOPE_BOUNDARIES = [
        'html' => true, 'table' => true, 'template' => true,
    ];

    const TABLE_SCOPED_STARTS = [
        'thead' => true, 'tbody' => true, 'tfoot' => true,
        'caption' => true, 'colgroup' => true, 'tr' => true, 'td' => true,
        'th' => true,
    ];

    const TABLE_STRUCTURE_STARTS = [
        'caption' => true, 'col' => true, 'colgroup' => true,
        'tbody' => true, 'td' => true, 'tfoot' => true, 'th' => true,
        'thead' => true, 'tr' => true,
    ];

    const TABLE_SECTIONS = [
        'thead' => true, 'tbody' => true, 'tfoot' => true,
    ];

    const TABLE_STRUCTURAL_ELEMENTS = [
        'caption' => true, 'colgroup' => true, 'thead' => true,
        'tbody' => true, 'tfoot' => true,
    ];

    const ALLOWED_IN_TABLE = [
        'caption' => true, 'colgroup' => true, 'thead' => true,
        'tbody' => true, 'tfoot' => true, 'style' => true, 'script' => true,
        'template' => true,
    ];

    const SELECT_TABLE_ELEMENTS = [
        'caption' => true, 'table' => true, 'tbody' => true,
        'tfoot' => true, 'thead' => true, 'tr' => true, 'td' => true,
        'th' => true,
    ];

    const HEADING_ELEMENTS = [
        'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true,
        'h5' => true, 'h6' => true,
    ];

    const SCOPED_END_TAGS = [
        'address' => true, 'article' => true, 'aside' => true,
        'blockquote' => true, 'button' => true, 'center' => true,
        'details' => true, 'dialog' => true, 'dir' => true, 'div' => true,
        'dl' => true, 'fieldset' => true, 'figcaption' => true,
        'figure' => true, 'footer' => true, 'header' => true,
        'hgroup' => true, 'listing' => true, 'main' => true, 'menu' => true,
        'nav' => true, 'ol' => true, 'pre' => true, 'search' => true,
        'section' => true, 'summary' => true, 'ul' => true,
        'applet' => true, 'marquee' => true, 'object' => true,
    ];

    const FORMATTING_ELEMENTS = [
        'a' => true, 'b' => true, 'big' => true, 'code' => true,
        'em' => true, 'font' => true, 'i' => true, 'nobr' => true,
        's' => true, 'small' => true, 'strike' => true, 'strong' => true,
        'tt' => true, 'u' => true,
    ];

    const FORMATTING_MARKER_ELEMENTS = [
        'applet' => true, 'caption' => true, 'marquee' => true,
        'object' => true, 'td' => true, 'th' => true, 'template' => true,
    ];

    const FORMATTING_BREAK_STARTS = [
        'address' => true, 'applet' => true, 'article' => true,
        'aside' => true, 'blockquote' => true, 'button' => true,
        'caption' => true, 'center' => true, 'col' => true,
        'colgroup' => true, 'dd' => true, 'details' => true, 'dir' => true,
        'div' => true, 'dl' => true, 'dt' => true, 'fieldset' => true,
        'figcaption' => true, 'figure' => true, 'footer' => true,
        'form' => true, 'h1' => true, 'h2' => true, 'h3' => true,
        'h4' => true, 'h5' => true, 'h6' => true, 'header' => true,
        'hgroup' => true, 'hr' => true, 'li' => true, 'listing' => true,
        'main' => true, 'marquee' => true, 'menu' => true, 'nav' => true,
        'object' => true, 'ol' => true, 'p' => true, 'plaintext' => true,
        'pre' => true, 'search' => true, 'section' => true,
        'select' => true, 'table' => true, 'tbody' => true, 'td' => true,
        'tfoot' => true, 'th' => true, 'thead' => true, 'template' => true,
        'tr' => true, 'ul' => true,
    ];

    const SVG_HTML_INTEGRATION_POINTS = [
        'foreignobject' => true, 'desc' => true, 'title' => true,
    ];

    /**
     * The SVG tag-name case adjustments from the tree construction rules,
     * keyed by lowercase tokenized name.
     */
    const SVG_TAG_NAMES = [
        'altglyph' => 'altGlyph', 'altglyphdef' => 'altGlyphDef',
        'altglyphitem' => 'altGlyphItem', 'animatecolor' => 'animateColor',
        'animatemotion' => 'animateMotion', 'animatetransform' => 'animateTransform',
        'clippath' => 'clipPath', 'feblend' => 'feBlend',
        'fecolormatrix' => 'feColorMatrix', 'fecomponenttransfer' => 'feComponentTransfer',
        'fecomposite' => 'feComposite', 'feconvolvematrix' => 'feConvolveMatrix',
        'fediffuselighting' => 'feDiffuseLighting', 'fedisplacementmap' => 'feDisplacementMap',
        'fedistantlight' => 'feDistantLight', 'fedropshadow' => 'feDropShadow',
        'feflood' => 'feFlood', 'fefunca' => 'feFuncA', 'fefuncb' => 'feFuncB',
        'fefuncg' => 'feFuncG', 'fefuncr' => 'feFuncR',
        'fegaussianblur' => 'feGaussianBlur', 'feimage' => 'feImage',
        'femerge' => 'feMerge', 'femergenode' => 'feMergeNode',
        'femorphology' => 'feMorphology', 'feoffset' => 'feOffset',
        'fepointlight' => 'fePointLight', 'fespecularlighting' => 'feSpecularLighting',
        'fespotlight' => 'feSpotLight', 'fetile' => 'feTile',
        'feturbulence' => 'feTurbulence', 'foreignobject' => 'foreignObject',
        'glyphref' => 'glyphRef', 'lineargradient' => 'linearGradient',
        'radialgradient' => 'radialGradient', 'textpath' => 'textPath',
    ];

    const MATHML_TEXT_INTEGRATION_POINTS = [
        'mi' => true, 'mo' => true, 'mn' => true, 'ms' => true,
        'mtext' => true,
    ];

    const FOREIGN_BREAKOUT_ELEMENTS = [
        'b' => true, 'big' => true, 'blockquote' => true, 'body' => true,
        'br' => true, 'center' => true, 'code' => true, 'dd' => true,
        'div' => true, 'dl' => true, 'dt' => true, 'em' => true,
        'embed' => true, 'h1' => true, 'h2' => true, 'h3' => true,
        'h4' => true, 'h5' => true, 'h6' => true, 'head' => true,
        'hr' => true, 'i' => true, 'img' => true, 'li' => true,
        'listing' => true, 'menu' => true, 'meta' => true, 'nobr' => true,
        'ol' => true, 'p' => true, 'pre' => true, 'ruby' => true,
        's' => true, 'small' => true, 'span' => true, 'strong' => true,
        'strike' => true, 'sub' => true, 'sup' => true, 'table' => true,
        'tt' => true, 'u' => true, 'ul' => true, 'var' => true,
    ];
}
