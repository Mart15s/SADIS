import ResponsiveList from './ResponsiveList.jsx'

export default function ResponsiveTable({
  columns,
  items,
  getKey,
  renderCard,
  tableLabel = 'Resource table',
  cardListLabel = 'Resource list',
  className = '',
}) {
  return (
    <div className={`responsive-table ${className}`.trim()}>
      <div className="table-wrap responsive-table-scroll" role="region" aria-label={tableLabel} tabIndex={0}>
        <table>
          <thead>
            <tr>
              {columns.map((column) => (
                <th key={column.key} className={column.className}>
                  {column.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {items.map((item) => (
              <tr key={getKey(item)}>
                {columns.map((column) => (
                  <td key={column.key} className={column.cellClassName}>
                    {column.render(item)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <ResponsiveList className="responsive-table-cards" ariaLabel={cardListLabel}>
        {items.map((item) => (
          <div key={getKey(item)} className="responsive-table-card-item">
            {renderCard(item)}
          </div>
        ))}
      </ResponsiveList>
    </div>
  )
}
