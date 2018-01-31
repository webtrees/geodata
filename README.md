# webtrees-geodata

Geographic data for genealogists.

## Identifiers and place names

Identifiers, file and folder names should be the English name of a place, written in
ASCII characters.
There are two reasons for this.  Firstly, some filesystems do not support non-latin
characters.  Secondly, some countries have multiple official languages and place names.

Where the local name of a place is different to the English/ASCII name, then the
translated version is stored as a property of the data.geojson file.

Where there are several places with the same name, include disambiguation
in parentheses.

* `England/Northamptonshire/Ashton (near Oundle)`
* `England/Northamptonshire/Ashton (near Roade)`

## Places, regions and hierarchy

The hierarchy of places should be the one prefered by the majority of genealogists.
For example, British genealogists tend to use the traditional counties in preference
to the modern administrative regions.

However, genealogical data often refers to regions that no longer exist, or that
are not part of the preferred geographical hierarchy.  We may know that someone
was born in "Ireland" - without knowing if the birthplace was in Northern Ireland
or the Replublic of Ireland.

Therefore, we include details of such regions, as an aid to locating them on a map.

## Coordinates

Coordinates should use a maximum of 5 decimal places.  This is a resolution of
approximately one metre.

## Which flag

Many countries have different [state flags](https://en.wikipedia.org/wiki/State_flag) and
[civil flags](https://en.wikipedia.org/wiki/Civil_flag).  Here we use the civil flag.

Many territories have disputed ownership or have no official flag.  For example,
[Northern Ireland](https://en.wikipedia.org/wiki/Northern_Ireland_flags_issue).
Here we use the flag that, according to Wikipedia, appears to have the greatest
recognition.

## Image format

Images in SVG format are prefered.  If no SVG image is available, then a PNG can be used.

## geojson format

You can validate geojson files using [geojsonlint.com](http://geojsonlint.com/)

## Licences

This project can only accept contributions that are in the public domain or that
have a free redistribution licence.  [Wikipedia](https://www.wikipedia.org) and
[Wikimedia Commons](https://commons.wikimedia.org) are a great source of such data!
