// @vitest-environment node
import { readdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import process from 'node:process'
import { describe, expect, it } from 'vitest'

function productionSources(directory = 'src') {
  return readdirSync(resolve(process.cwd(), directory), { withFileTypes: true }).flatMap((entry) => {
    const path = `${directory}/${entry.name}`
    if (entry.isDirectory()) return entry.name === 'test' ? [] : productionSources(path)
    if (!/\.(?:js|jsx)$/u.test(entry.name) || /\.test\.(?:js|jsx)$/u.test(entry.name)) return []
    return [path]
  })
}

const primarySources = productionSources()

describe('English primary interface source', () => {
  it.each(primarySources)('%s contains no Lithuanian interface characters', (path) => {
    const source = readFileSync(resolve(process.cwd(), path), 'utf8')
    expect(source).not.toMatch(/[ĄČĘĖĮŠŲŪŽąčęėįšųūž]/u)
    expect(source).not.toMatch(
      /\b(Uzdaryti|Issaugoti|Atsaukti|Atšaukti|Prisijungti|Naudotojas|Nustatymai|Nenurodyta|Sklypas|Zonos|Darzas|Bendruomene|Rodyti|Riba|Ribos|Plotas|Perimetras|Planas|Plano|Nauja|Naujas|Centras|Santrauka|Rodiniai|Privatus|Bendrinamas|Miestas|Pavadinimas|Aprasymas|Atgal|Grizti|Saugoti|Trinti|Salinti|Prideti|Redaguoti|Pasirinkti|Pasirinkite|Visi|Nera|Reikia|Paruosta|Atidaryti|Ikeliama|Daugiametis|Rotacija|Sklypai|Savininkas|Nezinomas|Pasirinkta|Augalas|Augalai|Duomenys)\b/iu,
    )
  })

  it('declares explicit narrow-phone and mobile layouts for the field editor', () => {
    const css = readFileSync(resolve(process.cwd(), 'src/index.css'), 'utf8')
    expect(css).toContain('@media (max-width: 359px)')
    expect(css).toContain('@media (max-width: 720px)')
    expect(css).toContain('.field-editor-sheet')
  })
})
