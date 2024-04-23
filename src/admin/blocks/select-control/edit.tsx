/**
 * External dependencies
 */
import { useWooBlockProps } from '@woocommerce/block-templates';
import { SelectControl } from '@wordpress/components';
import { MultiSelectControl } from '@codeamp/block-components';
import parse from 'html-react-parser';
import {
	__experimentalUseProductEntityProp as useProductEntityProp,
} from '@woocommerce/product-editor';

export function Edit({ attributes, context: { postType } } ) {
	const blockProps = useWooBlockProps( attributes );
	const {
		title,
		property,
		options,
		help,
		multiple,
		disabled,
	} = attributes;

	const [ value, setValue ] = useProductEntityProp<string>( property, { postType } );

	function setData( selected: string | string[] | number[] ) {
		if ( Array.isArray( selected ) && selected.every( item => ! isNaN( item ) ) ) {
			setValue( selected.map( Number ) );
		} else {
			setValue( selected );
		}
	}

	const CustomSelectControl = multiple ? MultiSelectControl : SelectControl;

	return (
		<div {...blockProps}>
			<CustomSelectControl
				label={ title }
				options={ options }
				value={ value || [] }
				onChange={ setData }
				help={ parse( help ) }
				disabled={ disabled }
			/>
		</div>
	);
}
