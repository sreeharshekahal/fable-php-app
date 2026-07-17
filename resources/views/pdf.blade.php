<!DOCTYPE html>
<html>
  <head>
    <meta charset="UTF-8" />
    <link
      href="https://fonts.googleapis.com/css?family=Roboto"
      rel="stylesheet"
    />
    <title>{{$title}}</title>
<style>
  @font-face {
    font-family: 'NotoSansDevanagari';
    src: url("{{ storage_path('fonts/NotoSansDevanagari-Regular.ttf') }}") format('truetype');
  }

  @font-face {
    font-family: 'Roboto';
    src: url("{{ storage_path('fonts/Roboto-Regular.ttf') }}") format('truetype');
  }

  body, pre, h1 {
    font-family: 'NotoSansDevanagari', 'Roboto', sans-serif;
  }
  pre {
    font-family: 'NotoSansDevanagari', 'Roboto', sans-serif;
    white-space: pre-wrap;
    line-height: 130%;
    font-size: 14pt;
    display: block;
    /* padding: 4%; */
    page-break-inside: avoid;
  }
 
  * {
    margin: 0;
    padding: 0;
  }

  h1{
    font-family: 'NotoSansDevanagari', 'Roboto', sans-serif;
    font-size: 24pt;
    text-decoration: underline;
  }
 
  body {
    font-family: 'NotoSansDevanagari', 'Roboto', sans-serif;
    margin: 2rem;
    line-height: 1;
    display:flex;
  }
 
  header {
    font-family: 'NotoSansDevanagari', 'Roboto', sans-serif;
    font-size: 24pt;
    position: relative; /* Changed from fixed */
    width: 100%;
    /* padding: 2% 4%; */
    page-break-inside: avoid;
    page-break-after: avoid;
    text-align: center;
    margin-bottom: 20px;
  }

  .content1{
    margin-left: 20px;
    margin-right: 20px;
  }
 
  footer {
    position: fixed;
    bottom: 0;
    width: 90%;
    padding-left: 1%;
    align-items: center;
  }
 
  .page-content {
    page-break-inside: auto;
    page-break-before: auto;
  }

  .grade5{
     font-size: 16px !important;
  }
  
  .f20{
     font-size: 25px !important;
  }
</style>
  </head>
 
  <body>
     <header>
  <div style="font-family: 'NotoSansDevanagari', 'Roboto', sans-serif; font-size:24pt; text-decoration:underline;">
    {{ $title }}
  </div>
</header>
    <?php 
      $class = '';
      if($grade_level == 5){
        $class = 'grade5';
      }

      if($language_name == 'Marathi' || $language_name == 'Hindi'){
        $class = 'f20';
      }

    ?>
    <div class="content1">
      <pre class="<?= $class ?>">{{$content}}</pre>
    </div>
    <center>
        <footer>
            <hr>
            <div><img src="https://sensei-prod-new.s3.ap-south-1.amazonaws.com/FABLe+-+updated+logo.png"
                    style="border: 0px; width: 50px; height: 50px; margin: 0px; float:left">
            </div>
            <p style="font-size:14px;float: right;">Passage: {{$grade_level}}-{{$passage_number}}</p>
            <center>
                <p style="font-size: 12px;">
                    Stories adapted from www.storyweaver.org.in <br>
                    published by Pratham Books(&copy;Pratham Books) under a CC BY 4.0 <br>
                    license on StoryWeaver. For a detailed reference, refer to the manual.</p>
            </center>
        </footer>
    </center>
  </body>
</html>