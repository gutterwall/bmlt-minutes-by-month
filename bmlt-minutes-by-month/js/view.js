/**
 * BMLT Minutes by Month: a closed month's PDF previews load when the month is opened, so a long archive does not
 * download every PDF with the page. Without this script the File block's link and Download button still work.
 *
 * Browsers (Chrome among them) do not load an <object> whose address is set after it was created, so each preview is
 * replaced by a copy that has the address. Where WordPress's File block script hides previews (browsers that cannot
 * show PDFs, such as on phones), nothing is downloaded.
 */
document.addEventListener(
	'toggle',
	function ( event ) {
		var month = event.target;
		if ( ! month.open || ! month.classList || ! month.classList.contains( 'bmbm__month' ) ) {
			return;
		}
		month.querySelectorAll( 'object[data-bmbm-src]' ).forEach( function ( embed ) {
			if ( embed.hidden ) {
				return; // No preview here (a phone, for one): the link and Download button are enough.
			}
			var copy = embed.cloneNode( true );
			copy.setAttribute( 'data', embed.getAttribute( 'data-bmbm-src' ) );
			copy.removeAttribute( 'data-bmbm-src' );
			embed.parentNode.replaceChild( copy, embed );
		} );
	},
	true // "toggle" does not bubble.
);
