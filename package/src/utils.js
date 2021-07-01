/* JS utility functions */

/**
 * Sets a value on a (nested) key.
 * @param {object} obj - The object to update.
 * @param {array} keys - The path of keys to the value.
 * @param value - The new value.
 */
export function deepSet (obj, keys, value) {
  const key = keys.shift()
  if (keys.length > 0) {
    if (typeof obj[key] === 'undefined') {
      obj[key] = {}
    }
    deepSet(obj[key], keys, value)
  }
  else {
    obj[key] = value
  }
}

/**
 * Convert kebap and snake case strings to camel case.
 * @param {string} str - The string to convert.
 */
export function camelCase (str) {
  return str.replace(/[-_]([a-z])/g, (g) => g[1].toUpperCase())
}
