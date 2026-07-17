<!DOCTYPE html>
<html lang="hi">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta charset="UTF-8" />
    <title>{!! $title !!}</title>
    <style>
        @font-face {
            font-family: 'FreeSans';
            font-weight: normal;
            font-style: normal;
        }

        @font-face {
            font-family: 'Roboto';
            src: url("{{ storage_path('fonts/Roboto-Regular.ttf') }}") format('truetype');
            font-weight: normal;
            font-style: normal;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'FreeSans', sans-serif;
            margin: 0;
            padding: 0;
            line-height: 1;
        }

        header {
            font-family: 'FreeSans', sans-serif;
            font-size: 22pt;
            width: 100%;
            page-break-inside: avoid;
            page-break-after: avoid;
            text-align: center;
            margin-bottom: 15px;
            text-decoration: underline;
            font-weight: bold;
        }

        .content-wrapper {
            page-break-inside: avoid;
            margin-bottom: 80px;
        }

        .content1 {
            margin-left: 0;
            margin-right: 0;
            padding: 0 5px;
        }

        .passage-content {
            font-family: 'FreeSans', sans-serif;
            line-height: 1.6;
            font-size: 14pt;
            text-align: justify;
            /* Critical mPDF-specific CSS for Devanagari rendering */
            use-otl: PHP;
            use-kashida: yes;
            /* Enhanced rendering properties */
            text-rendering: optimizeLegibility;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            font-feature-settings: "liga" 1, "clig" 1, "calt" 1;
            font-variant-ligatures: common-ligatures contextual;
        }

        footer {
            position: fixed;
            bottom: 0;
            bottom: -10px;
            left: 0;
            right: 0;
            width: 100%;
        }

        footer hr {
            border: 0;
            border-top: 1px solid #000;
            margin-bottom: 8px;
        }

        .footer-table {
            width: 100%;
            border-collapse: collapse;
            font-family: 'Roboto', sans-serif !important;
        }

        .footer-table td {
            vertical-align: middle;
        }

        .attribution {
            text-align: center;
            font-size: 11px;
            line-height: 1.2;
        }

        .passage-info {
            text-align: right;
            font-size: 14px;
        }

        .content-wrapper {
            /* Margin to prevent text from hitting the footer */
            margin-bottom: 150px;
        }

        .passage-content {
            font-size: 14pt;
            white-space: pre-wrap;
            text-align: left;
            margin-bottom: 20px;
        }

        /* Language-specific styling */
        .passage-content.hindi-marathi {
            font-size: 16pt !important;
            line-height: 1.8 !important;
            letter-spacing: -0.02em;
            use-otl: PHP;
            use-kashida: yes;
        }

        .passage-content.grade5 {
            font-size: 15pt !important;
        }

        .passage-content.english {
            font-family: 'Roboto', sans-serif !important;
            font-size: 14pt !important;
            line-height: 1.6 !important;
            white-space: pre-wrap !important;
            /* Preserve spaces and allow wrapping */
            word-wrap: break-word !important;
        }

        .passage-content.english.grade2 {
            font-size: 13pt !important;
            line-height: 2.1 !important;
        }

        .passage-content.english.grade3 {
            font-size: 13pt !important;
            line-height: 2.1 !important;
        }

        .passage-content.english.grade4 {
            font-size: 13pt !important;
            line-height: 2 !important;
        }

        .passage-content.english.grade5 {
            font-size: 13pt !important;
            line-height: 1.9 !important;
        }

        /* Add paragraph indentation for English */
        .passage-content.english p {
            text-indent: 2em;
            margin-bottom: 0.5em;
        }
    </style>
</head>

<body>
    <header>{!! $title !!}</header>

    <?php
    $class = 'english';
    
    if ($language_name == 'Marathi' || $language_name == 'Hindi') {
        $class = 'hindi-marathi';
    }
    
    if ($grade_level == 5) {
        $class .= ' grade5';
    }
    
    ?>

    <div class="content-wrapper">
        <div class="content1">
            <?php if ($language_name == 'English'): ?>
            <pre class="passage-content grade<?= $grade_level ?> <?= $class ?>">{!! preg_replace('/\. +/', '. ', $content) !!}</pre>
            <?php else: ?>
            <div class="passage-content <?= $class ?>">{!! nl2br($content) !!}</div>
            <?php endif; ?>
        </div>
    </div>

    <footer>
        <hr>
        <table class="footer-table">
            <tr>
                <td style="width: 15%;">
                    <img src="https://sensei-prod-new.s3.ap-south-1.amazonaws.com/FABLe+-+updated+logo.png"
                        style="width: 55px; height: 55px;">
                </td>
                <td class="attribution" style="width: 65%;">
                    Stories adapted from www.storyweaver.org.in<br>
                    published by Pratham Books (&copy;Pratham Books) under a CC BY 4.0<br>
                    license on StoryWeaver. For a detailed reference, refer to the manual.
                </td>
                <td class="passage-info" style="width: 20%;">
                    Passage: {{ $grade_level }}-{{ $passage_number }}
                </td>
            </tr>
        </table>
    </footer>
</body>

</html>
