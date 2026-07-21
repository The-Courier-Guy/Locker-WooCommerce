/**
 * External dependencies
 */
import { getSetting } from '@woocommerce/settings'

/**
 * TCG Locker data comes form the server passed on a global object.
 */
export const getPudoServerData = () => {
  const pudoServerData = getSetting('pudo_data', null)
  if (!pudoServerData) {
    throw new Error('Pudo initialization data is not available')
  }
  return pudoServerData
}
