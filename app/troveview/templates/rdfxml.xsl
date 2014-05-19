<?xml version="1.0"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns="http://www.w3.org/1999/xhtml" xmlns:rdfs="http://www.w3.org/2000/01/rdf-schema#" xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:foaf="http://xmlns.com/foaf/0.1/">
  
  <xsl:output method="html"/>

  <xsl:template match="/">
    <html>
      <head>
        <title><xsl:value-of select="/rdf:RDF/foaf:Document[1]/rdfs:label" /></title>
        <link rel="stylesheet" type="text/css" href="/templates/troveview/rdfxml.css" />
      </head>
      <body>
        <div id="main">
          <xsl:apply-templates/>
        </div>
      </body>
    </html>
  </xsl:template>

  <!-- elements which don't contain other nodes get CSS classes:
            indent
            _tag_NODENAME
            __tag__PARENTNAME__NODENAME
       -->
  <xsl:template match="*">
    <div class="indent _tag_{local-name(.)} __tag__{local-name(..)}__{local-name(.)}">
      <span class="markup">&lt;</span>
      <span class="start-tag"><xsl:value-of select="name(.)"/></span>
      <xsl:apply-templates select="@*"/>
      <span class="markup">/&gt;</span>
    </div>
  </xsl:template>

  <!-- elements containing other nodes get CSS classes:
            indent
            _tag_NODENAME
            __tag__PARENTNAME__NODENAME
       -->
  <xsl:template match="*[node()]">
    <div class="indent _tag_{local-name(.)} __tag__{local-name(..)}__{local-name(.)}">
      <span class="markup">&lt;</span>
      <span class="start-tag"><xsl:value-of select="name(.)"/></span>
      <xsl:apply-templates select="@*"/>
      <span class="markup">&gt;</span>

      <span class="text"><xsl:apply-templates/></span>

      <span class="markup">&lt;/</span>
      <span class="end-tag"><xsl:value-of select="name(.)"/></span>
      <span class="markup">&gt;</span>
    </div>
  </xsl:template>

  <!-- @href and @change_href attributes get CSS classes:
            attribute-value
            _attr_ATTRNAME
            __attr__NODENAME__ATTRNAME
       -->
  <xsl:template match="@about|@resource|@datatype">
    <xsl:text> </xsl:text>
    <span class="attribute-name"><xsl:value-of select="name(.)"/></span>
    <span class="markup">=</span>
    <span class="attribute-quote">"</span><span class="attribute-value _attr_{local-name(.)} __attr__{local-name(..)}__{local-name(.)}"><a href="{.}"><xsl:value-of select="."/></a></span><span class="attribute-quote">"</span>
  </xsl:template>

  <!-- all other attributes get CSS classes:
            attribute-value
            _attr_ATTRNAME
            __attr__NODENAME__ATTRNAME
       -->
  <xsl:template match="@*">
    <xsl:text> </xsl:text>
    <span class="attribute-name"><xsl:value-of select="name(.)"/></span>
    <span class="markup">=</span>
    <span class="attribute-quote">"</span><span class="attribute-value _attr_{local-name(.)} __attr__{local-name(..)}__{local-name(.)}"><xsl:value-of select="."/></span><span class="attribute-quote">"</span>
  </xsl:template>

  <!-- text nodes get CSS classes:
            indent
            text
            _text_PARENTNAME
       -->
  <xsl:template match="text()">
    <xsl:if test="normalize-space(.)">
      <div class="indent text _text_{local-name(..)}"><xsl:value-of select="."/></div>
    </xsl:if>
  </xsl:template>

  <!-- PIs and comments in XML are stripped out -->
  <xsl:template match="processing-instruction()|comment()">
  </xsl:template>
</xsl:stylesheet>
