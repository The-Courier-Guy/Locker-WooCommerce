/**
 * External dependencies
 */
import { decodeEntities } from '@wordpress/html-entities'
import { __ } from '@wordpress/i18n'
import { registerShippingMethod } from '@woocommerce/blocks-registry'

/**
 * Internal dependencies
 */
import { SHIPPING_METHOD_NAME } from './constants'
import { getPudoServerData } from './pudo-utils'

const Content = () => {
  return decodeEntities(getPudoServerData()?.description || '')
}

const Label = () => {
  return (
    <img
      src={getPudoServerData()?.logo_url}
      alt={getPudoServerData()?.title}
    />
  )
}

registerShippingMethod({
  name: SHIPPING_METHOD_NAME,
  label: <Label/>,
  ariaLabel: __('Pudo shipping method', 'pudo-for-wc'),
  content: <Content/>,
  edit: <Content/>,
  supports: {
    features: getPudoServerData()?.supports ?? [],
  },
})
