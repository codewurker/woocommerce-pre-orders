/**
 * External dependencies
 */
import { useWooBlockProps } from '@woocommerce/block-templates';
import {
	TextControl,
	DateTimePicker,
	Popover,
	Card,
	CardBody,
	Button,
	Flex,
	FlexItem,
	BaseControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import {
	__experimentalUseProductEntityProp as useProductEntityProp,
} from '@woocommerce/product-editor';
import { __ } from '@wordpress/i18n';

export function Edit({ attributes, context: { postType } } ) {
	const blockProps = useWooBlockProps( attributes );
	const {
		title,
		property,
		help,
		date,
		disabled,
	} = attributes;

	const [ value, setValue ] = useProductEntityProp<string>( property, { postType } );
	const [ isPickerVisible, setIsPickerVisible ] = useState( false );

	function formatDateTime( date: string ) {
		const dateTime = new Date( date );

		if ( isNaN( dateTime ) ) {
			return '';
		}

		// Get the components of the date and time
		const year = dateTime.getFullYear();
		const month = String(dateTime.getMonth() + 1).padStart(2, '0'); // Months are zero-based
		const day = String(dateTime.getDate()).padStart(2, '0');
		const hours = String(dateTime.getHours()).padStart(2, '0');
		const minutes = String(dateTime.getMinutes()).padStart(2, '0');

		// Format the date and time as "YYYY-MM-DD HH:MM"
		return `${year}-${month}-${day} ${hours}:${minutes}`;
	}

	return (
		<div {...blockProps}>
			<BaseControl
				label={ title }
				help={ help }
			>
				<Flex>
					<FlexItem isBlock>
						<TextControl
							onFocus={ () => setIsPickerVisible( true ) }
							autoComplete="off"
							placeholder='YYYY-MM-DD HH:MM'
							value={ formatDateTime( value ) || '' }
							disabled={ disabled }
						/>
					</FlexItem>
					<FlexItem>
						<Button
							variant='link'
							disabled={ disabled }
							onClick={ () => setValue( '' ) }
						>
							{ __( 'Clear', 'woocommerce-pre-orders' ) }
						</Button>
					</FlexItem>
				</Flex>
			</BaseControl>
			{ isPickerVisible && (
				<Popover
					onFocusOutside={ () => setIsPickerVisible( false ) }
				>
					<Card>
						<CardBody>
							<DateTimePicker
								currentDate={ value ? new Date( value ).toISOString() : '' }
								onChange={ ( date ) => {
									setValue( formatDateTime( date ) );
								} }
							/>
						</CardBody>
					</Card>
				</Popover> )
			}
		</div>
	);
}
